<?php

namespace Tests\Feature\Monitoring;

use App\Ledger\AccountService;
use App\Ledger\Events\EventBus;
use App\Ledger\Events\OutboxPublisher;
use App\Ledger\LedgerService;
use App\Monitoring\MonitoringConsumer;
use App\Monitoring\RuleAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class MonitoringRulesTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private RuleAdmin $rules;
    private EventBus $bus;
    private int $cash;
    private int $alice;
    private int $bob;
    private int $carol;

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
        $this->bob = $accounts->open('C-BOB', 'Bob', 'liability');
        $this->carol = $accounts->open('C-CAROL', 'Carol', 'liability');
    }

    private function deposit(int $to, int $amount, string $key)
    {
        return $this->ledger->post('deposit', [
            ['account_id' => $this->cash, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function withdraw(int $from, int $amount, string $key)
    {
        return $this->ledger->post('withdrawal', [
            ['account_id' => $from, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $this->cash, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function transfer(int $from, int $to, int $amount, string $key)
    {
        return $this->ledger->post('transfer', [
            ['account_id' => $from, 'direction' => 'D', 'amount' => $amount],
            ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
        ], $key);
    }

    private function publish(): void
    {
        $r = (new OutboxPublisher($this->bus))->publishBatch(1000);
        $this->assertSame(0, $r['failed']);
    }

    public function test_structuring_alerts_on_repeated_just_below_threshold_amounts(): void
    {
        $this->rules->define('STRUCT', 'structuring', 'Structuring', 'test rationale',
            ['threshold_sen' => 10_000, 'band_percent' => 10, 'min_count' => 3, 'window_minutes' => 60,
             'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');

        $this->deposit($this->alice, 8_999, 'k1');                 // below the band
        $this->deposit($this->alice, 10_000, 'k2');                // at the threshold, not below it
        $t1 = $this->deposit($this->alice, 9_000, 'k3');           // in band (lower edge)
        $t2 = $this->deposit($this->alice, 9_500, 'k4');
        $t3 = $this->deposit($this->alice, 9_999, 'k5');           // third in-band: alert
        $this->deposit($this->alice, 9_100, 'k6');                 // fourth: suppressed
        $this->publish();

        $alerts = DB::table('monitoring_alerts')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($t3->transactionId, $alerts[0]->transaction_id);
        $this->assertStringContainsString('RM 90.00', $alerts[0]->explanation);
        $this->assertStringContainsString('RM 99.99', $alerts[0]->explanation);

        $d = json_decode($alerts[0]->details, true);
        $this->assertSame(3, $d['observed_count']);
        $this->assertSame([$t1->transactionId, $t2->transactionId, $t3->transactionId], $d['evidence_transaction_ids']);
    }

    public function test_structuring_ignores_amounts_outside_the_band(): void
    {
        $this->rules->define('STRUCT', 'structuring', 'Structuring', 'test rationale',
            ['threshold_sen' => 10_000, 'band_percent' => 10, 'min_count' => 3, 'window_minutes' => 60,
             'directions' => ['D', 'C'], 'account_types' => ['liability']], 'high');

        foreach ([8_999, 10_000, 10_000, 20_000, 8_000] as $i => $amount) {
            $this->deposit($this->alice, $amount, "k$i");
        }
        $this->publish();

        $this->assertSame(0, DB::table('monitoring_alerts')->count());
    }

    public function test_rapid_in_out_alerts_when_most_of_the_money_leaves_then_stays_quiet(): void
    {
        $this->rules->define('RAPID', 'rapid_in_out', 'Rapid in-out', 'test rationale',
            ['min_amount_sen' => 10_000, 'out_percent' => 80, 'window_minutes' => 60, 'account_types' => ['liability']], 'high');

        $this->deposit($this->alice, 20_000, 'k1');
        $this->withdraw($this->alice, 5_000, 'k2');                // 25% out: no alert
        $out = $this->withdraw($this->alice, 12_000, 'k3');        // 85% out: alert
        $this->withdraw($this->alice, 1_000, 'k4');                // still above 80%, but suppressed
        $this->publish();

        $alerts = DB::table('monitoring_alerts')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame($out->transactionId, $alerts[0]->transaction_id);
        $this->assertStringContainsString('RM 200.00', $alerts[0]->explanation);
        $this->assertStringContainsString('RM 170.00', $alerts[0]->explanation);

        $d = json_decode($alerts[0]->details, true);
        $this->assertSame(20_000, $d['credited_sen']);
        $this->assertSame(17_000, $d['moved_out_sen']);
    }

    public function test_rapid_in_out_ignores_money_that_left_before_the_credit(): void
    {
        $this->deposit($this->alice, 50_000, 'k0');
        $this->publish();
        // Simulate time passing: push the first deposit out of the window.
        DB::statement("UPDATE monitoring_activity SET occurred_at = occurred_at - interval '5 hours'");

        $this->rules->define('RAPID', 'rapid_in_out', 'Rapid in-out', 'test rationale',
            ['min_amount_sen' => 10_000, 'out_percent' => 80, 'window_minutes' => 60, 'account_types' => ['liability']], 'high');

        $this->withdraw($this->alice, 40_000, 'k1');               // leaves BEFORE the new credit
        $this->deposit($this->alice, 20_000, 'k2');
        $this->withdraw($this->alice, 1_000, 'k3');                // only 5% of the credit has left since
        $this->publish();

        // Counting the early 40,000 outflow would have produced a false alert here.
        $this->assertSame(0, DB::table('monitoring_alerts')->count());
    }

    public function test_new_beneficiary_alerts_once_per_pair_and_survives_replay_and_reversal(): void
    {
        $this->rules->define('NEWBEN', 'new_beneficiary', 'New beneficiary', 'test rationale',
            ['threshold_sen' => 10_000, 'account_types' => ['liability']], 'medium');

        $this->deposit($this->alice, 100_000, 'k0');
        $first = $this->transfer($this->alice, $this->bob, 20_000, 'k1');    // new + high value: alert
        $this->transfer($this->alice, $this->bob, 20_000, 'k2');             // known now
        $this->transfer($this->alice, $this->carol, 5_000, 'k3');            // new, below the limit: no alert, but now known
        $this->transfer($this->alice, $this->carol, 30_000, 'k4');           // known: no alert
        $back = $this->transfer($this->bob, $this->alice, 15_000, 'k5');     // other direction is a different pair: alert
        $this->publish();

        $alerts = DB::table('monitoring_alerts')->orderBy('id')->get();
        $this->assertCount(2, $alerts);
        $this->assertSame([$first->transactionId, $back->transactionId], $alerts->pluck('transaction_id')->all());
        $this->assertSame('C-BOB', json_decode($alerts[0]->details, true)['beneficiary_account_number']);
        $this->assertStringContainsString('not paid before', $alerts[0]->explanation);
        $this->assertSame(3, DB::table('monitoring_counterparties')->count());

        // Replay re-delivers history: nothing may change.
        $this->artisan('ledger:outbox-replay', ['consumer' => 'monitoring', '--from-id' => 1])->assertExitCode(0);
        $this->assertSame(2, DB::table('monitoring_alerts')->count());
        $this->assertSame(3, DB::table('monitoring_counterparties')->count());

        // A reversal flips directions, but it is not a payment: no relationship, no alert.
        $this->ledger->reverse($first->transactionId, 'r1');
        $this->publish();
        $this->assertSame(2, DB::table('monitoring_alerts')->count());
        $this->assertSame(3, DB::table('monitoring_counterparties')->count());
    }

    public function test_invalid_parameters_for_the_new_rules_are_rejected(): void
    {
        $bad = [
            ['structuring', ['threshold_sen' => 10_000, 'band_percent' => 80, 'min_count' => 3, 'window_minutes' => 60]],
            ['structuring', ['threshold_sen' => 10_000, 'band_percent' => 10, 'min_count' => 1, 'window_minutes' => 60]],
            ['rapid_in_out', ['min_amount_sen' => 10_000, 'out_percent' => 0, 'window_minutes' => 60]],
            ['rapid_in_out', ['min_amount_sen' => 10_000, 'out_percent' => 80, 'window_minutes' => 60, 'account_types' => ['nonsense']]],
            ['new_beneficiary', ['threshold_sen' => 0]],
        ];

        foreach ($bad as $i => [$type, $params]) {
            try {
                $this->rules->define("BAD-$i", $type, 'bad', 'test rationale', $params, 'low');
                $this->fail("Expected InvalidArgumentException for case $i");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, DB::table('monitoring_rules')->count());
    }
}