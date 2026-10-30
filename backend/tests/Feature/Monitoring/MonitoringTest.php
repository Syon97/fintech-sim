<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\LedgerEvent;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private RuleAdmin $rules;
    private EventBus $bus;
    private int $cash;
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->rules = app(RuleAdmin::class);
        $this->bus = new EventBus(app(MonitoringConsumer::class));
        $this->app->instance(EventBus::class, $this->bus);

        $accounts = app(AccountService::class);
        $this->cash = $accounts->open('GL-CASH', 'Bank cash', 'asset', 'MYR', true);
        $this->alice = $accounts->open('C-ALICE', 'Alice', 'liability');
    }

    private function deposit(int $amount, string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $this->alice, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function publish(): void
    {
        $r = (new OutboxPublisher($this->bus))->publishBatch(1000);
        $this->assertSame(0, $r['failed']);
    }

    private function large(int $threshold): int
    {
        return $this->rules->define('LARGE', 'large_amount', 'Large amount', 'test rationale',
            ['threshold_sen' => $threshold, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');
    }

    public function test_large_amount_creates_an_explained_alert_for_customer_accounts_only(): void
    {
        $versionId = $this->large(1_000_000);

        $this->deposit(5_000, 'k1');                 // below the limit
        $big = $this->deposit(1_000_000, 'k2');      // exactly at the limit
        $this->publish();

        $alerts = DB::table('monitoring_alerts')->get();
        $this->assertCount(1, $alerts); // the cash (asset) side of the same transaction is not alerted

        $a = $alerts[0];
        $this->assertSame($versionId, (int) $a->rule_version_id);
        $this->assertSame('C-ALICE', $a->account_number);
        $this->assertSame($big->transactionId, $a->transaction_id);
        $this->assertSame('high', $a->severity);
        $this->assertSame('open', $a->status);
        $this->assertStringContainsString('RM 10,000.00', $a->explanation);

        $d = json_decode($a->details, true);
        $this->assertSame(1_000_000, $d['threshold_sen']);
        $this->assertSame([$big->transactionId], $d['evidence_transaction_ids']);
    }

    public function test_velocity_alerts_on_crossing_the_limit_then_stays_quiet_for_the_window(): void
    {
        $this->rules->define('VELO', 'velocity', 'Velocity', 'test rationale',
            ['max_count' => 3, 'window_minutes' => 10, 'directions' => ['D', 'C'], 'account_types' => ['liability']], 'medium');

        $txs = [];
        for ($i = 1; $i <= 5; $i++) {
            $txs[] = $this->deposit(1_000, "k$i");
        }
        $this->publish();

        $alerts = DB::table('monitoring_alerts')->get();
        $this->assertCount(1, $alerts); // 4th transaction crosses the limit; the 5th is suppressed

        $this->assertSame($txs[3]->transactionId, $alerts[0]->transaction_id);
        $d = json_decode($alerts[0]->details, true);
        $this->assertSame(4, $d['observed_count']);
        $this->assertCount(4, $d['evidence_transaction_ids']);
    }

    public function test_replaying_history_creates_no_duplicate_alerts_or_activity(): void
    {
        $this->large(1_000);
        $this->deposit(5_000, 'k1');
        $this->deposit(5_000, 'k2');
        $this->publish();

        $this->assertSame(2, DB::table('monitoring_alerts')->count());
        $this->assertSame(4, DB::table('monitoring_activity')->count());

        $this->artisan('ledger:outbox-replay', ['consumer' => 'monitoring', '--from-id' => 1])->assertExitCode(0);

        $this->assertSame(2, DB::table('monitoring_alerts')->count());
        $this->assertSame(4, DB::table('monitoring_activity')->count());
    }

    public function test_a_new_rule_version_applies_going_forward_and_old_alerts_keep_their_version(): void
    {
        $v1 = $this->large(10_000);
        $this->deposit(5_000, 'k1');   // below v1's limit
        $this->publish();
        $this->assertSame(0, DB::table('monitoring_alerts')->count());

        $v2 = $this->large(1_000);     // new version of the same rule; v1 is retired
        $this->deposit(5_000, 'k2');
        $this->publish();

        $alert = DB::table('monitoring_alerts')->first();
        $this->assertSame($v2, (int) $alert->rule_version_id);
        $this->assertNotSame($v1, $v2);
        $this->assertSame('retired', DB::table('monitoring_rule_versions')->where('id', $v1)->value('status'));
        $this->assertSame(2, (int) DB::table('monitoring_rule_versions')->where('id', $v2)->value('version'));
    }

    public function test_invalid_rule_parameters_are_rejected_before_anything_is_stored(): void
    {
        try {
            $this->large(0);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('threshold_sen', $e->getMessage());
        }

        $this->assertSame(0, DB::table('monitoring_rules')->count());
    }

    public function test_rule_version_parameters_are_immutable_but_status_can_change(): void
    {
        $v = $this->large(1_000);

        DB::table('monitoring_rule_versions')->where('id', $v)->update(['status' => 'retired']); // allowed

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');
        DB::table('monitoring_rule_versions')->where('id', $v)->update(['parameters' => '{"threshold_sen": 1}']);
    }

    public function test_rule_versions_cannot_be_deleted(): void
    {
        $v = $this->large(1_000);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('not allowed');
        DB::table('monitoring_rule_versions')->where('id', $v)->delete();
    }

    public function test_monitoring_never_reads_ledger_tables(): void
    {
        $tables = 'entries|accounts|account_balances|transactions|idempotency_keys';

        foreach (File::allFiles(app_path('Monitoring')) as $file) {
            $src = $file->getContents();
            $name = $file->getFilename();

            $this->assertDoesNotMatchRegularExpression(
                "/\\b(FROM|JOIN|INTO|UPDATE)\\s+($tables)\\b|table\\(\\s*['\"]($tables)['\"]/i", $src, "$name touches a ledger table");
            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Ledger\\\\(?!Events\\\\)/', $src, "$name imports ledger internals");
        }
    }

    public function test_unsupported_event_schema_versions_fail_loudly(): void
    {
        $event = new LedgerEvent(1, 'x', 'transaction.posted', 2, 't', '2026-01-01 00:00:00+00', ['entries' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported');
        app(MonitoringConsumer::class)->handle($event);
    }
}