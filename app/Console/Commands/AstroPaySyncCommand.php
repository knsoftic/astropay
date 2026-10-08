<?php

namespace App\Console\Commands;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\AstroPayException;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\StatusSyncService;
use Illuminate\Console\Command;

/**
 * Manual reconciliation. Run it by hand when callbacks may have been missed
 * (AstroPay does not retry them). It is intentionally not scheduled.
 */
class AstroPaySyncCommand extends Command
{
    protected $signature = 'astropay:sync
        {order? : Check a single order by its orderId}
        {--type= : Only deposit or payout}
        {--currency= : Only INR, PKR, BDT or USDT}
        {--older-than=5 : Only orders sent at least this many minutes ago}
        {--limit=50 : Maximum number of orders to check}';

    protected $description = 'Query AstroPay for the status of unsettled orders and apply the result';

    public function handle(StatusSyncService $sync): int
    {
        $orderId = $this->argument('order');

        if (is_string($orderId) && $orderId !== '') {
            $transaction = AstroPayTransaction::query()->where('order_id', $orderId)->first();

            if ($transaction === null) {
                $this->error("No transaction with orderId {$orderId}.");

                return self::FAILURE;
            }

            return $this->checkAll($sync, collect([$transaction]));
        }

        $type = $this->option('type');
        $currency = $this->option('currency');

        if ($type !== null && TransactionType::tryFrom((string) $type) === null) {
            $this->error('--type must be deposit or payout.');

            return self::INVALID;
        }

        if ($currency !== null && Currency::tryFrom(strtoupper((string) $currency)) === null) {
            $this->error('--currency must be one of: '.implode(', ', Currency::values()).'.');

            return self::INVALID;
        }

        $olderThan = max(0, (int) $this->option('older-than'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $transactions = AstroPayTransaction::query()
            ->awaitingGateway()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($currency, fn ($q) => $q->where('currency', strtoupper((string) $currency)))
            ->where('submitted_at', '<=', now()->subMinutes($olderThan))
            ->oldest('submitted_at')
            ->limit($limit)
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('No unsettled orders to check.');

            return self::SUCCESS;
        }

        return $this->checkAll($sync, $transactions);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AstroPayTransaction>  $transactions
     */
    private function checkAll(StatusSyncService $sync, $transactions): int
    {
        $rows = [];
        $failures = 0;

        foreach ($transactions as $transaction) {
            $before = $transaction->status->value;

            try {
                $result = $sync->sync($transaction, 'console sync');
                $rows[] = [$transaction->order_id, $transaction->type->value, $transaction->currency->value, $before, $result->transaction->status->value, $result->outcome->value];
            } catch (AstroPayException $e) {
                $failures++;
                $rows[] = [$transaction->order_id, $transaction->type->value, $transaction->currency->value, $before, $before, 'error: '.$e->getMessage()];
            }
        }

        $this->table(['Order', 'Type', 'Currency', 'Before', 'After', 'Result'], $rows);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
