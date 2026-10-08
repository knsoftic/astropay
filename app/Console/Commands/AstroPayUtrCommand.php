<?php

namespace App\Console\Commands;

use App\Exceptions\AstroPay\AstroPayException;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\UtrService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class AstroPayUtrCommand extends Command
{
    protected $signature = 'astropay:utr
        {utr : The UPI transaction reference}
        {--order= : Attach (supplement) the UTR to this INR deposit orderId instead of only querying it}';

    protected $description = 'Query a UPI UTR, or attach it to an INR deposit (INR account only)';

    public function handle(UtrService $utr): int
    {
        $reference = (string) $this->argument('utr');
        $orderId = $this->option('order');

        try {
            if (is_string($orderId) && $orderId !== '') {
                $transaction = AstroPayTransaction::query()->where('order_id', $orderId)->first();

                if ($transaction === null) {
                    $this->error("No transaction with orderId {$orderId}.");

                    return self::FAILURE;
                }

                $data = $utr->supplement($transaction, $reference);
                $this->info("UTR attached to {$orderId}. Run `php artisan astropay:sync {$orderId}` in a few minutes.");
            } else {
                $data = $utr->query($reference);
            }
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::INVALID;
        } catch (AstroPayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
