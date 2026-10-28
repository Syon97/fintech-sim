<?php

namespace App\Console\Commands;

use App\Ledger\Exceptions\LedgerException;
use App\Ledger\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LedgerStressWorker extends Command
{
    protected $signature = 'ledger:stress-worker {run} {worker} {--ops=200}';
    protected $description = 'Internal: one worker process of ledger:stress';
    protected $hidden = true;

    public function handle(LedgerService $ledger): int
    {
        $run = $this->argument('run');
        $w = (int) $this->argument('worker');
        $ops = (int) $this->option('ops');
        mt_srand($w * 7919 + 13);

        $cust = DB::table('accounts')->where('account_number', 'like', "$run-CUST%")
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Every worker attacks the SAME targets in the SAME order: maximum contention.
        $targets = DB::table('idempotency_keys')->where('scope', 'ledger.post')
            ->where('key', 'like', "$run-target-%")->pluck('transaction_id')->all();
        sort($targets);
        $ti = 0;

        $s = ['posted' => 0, 'replays_ok' => 0, 'insufficient' => 0, 'reversed' => 0,
              'already_reversed' => 0, 'reverse_insufficient' => 0, 'unexpected' => []];

        for ($i = 0; $i < $ops; $i++) {
            if ($i % 10 === 0 && isset($targets[$ti])) {
                $this->tryReverse($ledger, $targets[$ti], "$run-w$w-rev$ti", $s);
                $ti++;
            }

            $pair = array_rand($cust, 2);
            shuffle($pair);
            $from = $cust[$pair[0]];
            $to = $cust[$pair[1]];
            $amount = mt_rand(1, 5000);
            $key = "$run-w$w-op$i";
            $lines = [
                ['account_id' => $from, 'direction' => 'D', 'amount' => $amount],
                ['account_id' => $to, 'direction' => 'C', 'amount' => $amount],
            ];

            try {
                $r = $ledger->post('transfer', $lines, $key);
                $s['posted']++;

                if (mt_rand(1, 5) === 1) { // simulate a client retrying the same request
                    $again = $ledger->post('transfer', $lines, $key);
                    $again->replayed && $again->transactionId === $r->transactionId
                        ? $s['replays_ok']++
                        : $s['unexpected'][] = "retry of $key did not replay";
                }
            } catch (LedgerException $e) {
                $e->reason === 'INSUFFICIENT_FUNDS'
                    ? $s['insufficient']++
                    : $s['unexpected'][] = $e->reason;
            } catch (Throwable $e) {
                $s['unexpected'][] = class_basename($e) . ': ' . substr($e->getMessage(), 0, 160);
            }
        }

        $this->line('RESULT ' . json_encode($s));

        return self::SUCCESS;
    }

    private function tryReverse(LedgerService $ledger, string $txId, string $key, array &$s): void
    {
        try {
            $ledger->reverse($txId, $key, 'stress');
            $s['posted']++;
            $s['reversed']++;
        } catch (LedgerException $e) {
            match ($e->reason) {
                'ALREADY_REVERSED' => $s['already_reversed']++,
                'INSUFFICIENT_FUNDS' => $s['reverse_insufficient']++,
                default => $s['unexpected'][] = $e->reason,
            };
        } catch (Throwable $e) {
            $s['unexpected'][] = class_basename($e) . ': ' . substr($e->getMessage(), 0, 160);
        }
    }
}