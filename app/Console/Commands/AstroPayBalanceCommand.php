<?php

namespace App\Console\Commands;

use App\Enums\AstroPay\Currency;
use App\Exceptions\AstroPay\AstroPayException;
use App\Services\AstroPay\AstroPayManager;
use Illuminate\Console\Command;

class AstroPayBalanceCommand extends Command
{
    protected $signature = 'astropay:balance {currency? : INR, PKR, BDT or USDT (default: every configured account)}';

    protected $description = 'Show the AstroPay merchant balance and today/yesterday summary';

    public function handle(AstroPayManager $astropay): int
    {
        $argument = $this->argument('currency');

        if (is_string($argument) && $argument !== '') {
            $currency = Currency::tryFrom(strtoupper($argument));

            if ($currency === null) {
                $this->error('Currency must be one of: '.implode(', ', Currency::values()).'.');

                return self::INVALID;
            }

            $currencies = [$currency];
        } else {
            $currencies = $astropay->configuredCurrencies();
        }

        if ($currencies === []) {
            $this->warn('No AstroPay account is configured. Add the merchant keys in Admin → Settings (or .env).');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($currencies as $currency) {
            $this->newLine();
            $this->line("<options=bold>{$currency->value}</>");

            try {
                $data = $astropay->client($currency)->balance()->data;
            } catch (AstroPayException $e) {
                $failed = true;
                $this->error($e->getMessage());

                continue;
            }

            $this->table(['Field', 'Value'], collect($data)->map(
                fn ($value, $key) => [$key, is_scalar($value) || $value === null ? (string) $value : json_encode($value)],
            )->values()->all());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
