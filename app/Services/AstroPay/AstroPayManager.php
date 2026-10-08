<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Enums\AstroPay\TransactionType;
use App\Exceptions\AstroPay\ConfigurationException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\URL;
use Psr\Log\LoggerInterface;

/**
 * Resolves per-currency credentials, limits and callback URLs (stored in the
 * database via Admin → Settings) and builds the API client for a currency's
 * merchant account.
 */
final class AstroPayManager
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly AstroPaySettings $settings,
    ) {}

    /**
     * Both keys are available: the account can be queried and its callbacks
     * verified, even when it is disabled for new orders.
     */
    public function hasCredentials(Currency $currency): bool
    {
        $account = $this->settings->account($currency);

        return $account['merchant_key'] !== null && $account['secret_key'] !== null;
    }

    /**
     * The currency accepts new deposits and withdrawals.
     */
    public function isEnabled(Currency $currency): bool
    {
        return $this->hasCredentials($currency) && $this->settings->account($currency)['enabled'];
    }

    /**
     * Currencies that accept new orders.
     *
     * @return list<Currency>
     */
    public function enabledCurrencies(): array
    {
        return array_values(array_filter(Currency::cases(), fn (Currency $currency) => $this->isEnabled($currency)));
    }

    /**
     * Currencies with saved credentials (enabled or not).
     *
     * @return list<Currency>
     */
    public function configuredCurrencies(): array
    {
        return array_values(array_filter(Currency::cases(), fn (Currency $currency) => $this->hasCredentials($currency)));
    }

    /**
     * @throws ConfigurationException
     */
    public function client(Currency $currency): AstroPayClient
    {
        if (! $this->hasCredentials($currency)) {
            throw new ConfigurationException(sprintf('AstroPay credentials for %s are not configured. Add them in Admin → Settings.', $currency->value));
        }

        $account = $this->settings->account($currency);
        $baseUrl = rtrim((string) $this->settings->baseUrl(), '/');

        if ($baseUrl === '') {
            throw new ConfigurationException('The AstroPay API base URL is not set. Save it in Admin → Settings.');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The AstroPay API base URL is not a valid URL.');
        }

        return new AstroPayClient(
            http: $this->app->make(HttpFactory::class),
            logger: $this->logger(),
            currency: $currency,
            merchantKey: (string) $account['merchant_key'],
            secretKey: (string) $account['secret_key'],
            baseUrl: $baseUrl,
            timeout: max(1, (int) $this->config->get('astropay.timeout', 30)),
            connectTimeout: max(1, (int) $this->config->get('astropay.connect_timeout', 10)),
            queryRetries: max(0, (int) $this->config->get('astropay.query_retries', 2)),
        );
    }

    /**
     * The secretKey used to verify callback signatures for this account.
     *
     * @throws ConfigurationException
     */
    public function secretKey(Currency $currency): string
    {
        if (! $this->hasCredentials($currency)) {
            throw new ConfigurationException(sprintf('AstroPay credentials for %s are not configured.', $currency->value));
        }

        return (string) $this->settings->account($currency)['secret_key'];
    }

    /**
     * @return array{min: string|null, max: string|null}
     */
    public function limits(Currency $currency, TransactionType $type): array
    {
        $account = $this->settings->account($currency);
        $prefix = $type === TransactionType::Deposit ? 'deposit' : 'payout';

        return [
            'min' => $account[$prefix.'_min'],
            'max' => $account[$prefix.'_max'],
        ];
    }

    /**
     * Absolute callbackUrl sent with every order, e.g.
     * https://pay.example.com/webhooks/astropay/deposit/INR
     *
     * @throws ConfigurationException
     */
    public function callbackUrl(TransactionType $type, Currency $currency): string
    {
        $path = route('astropay.webhook', ['type' => $type->value, 'currency' => $currency->value], false);
        $base = (string) $this->settings->callbackBaseUrl();

        $url = $base !== ''
            ? rtrim($base, '/').$path
            : rtrim(URL::to('/'), '/').$path;

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The AstroPay callback URL is invalid: '.$url);
        }

        if ($this->app->isProduction() && ! str_starts_with($url, 'https://')) {
            throw new ConfigurationException('AstroPay callbacks require a public HTTPS URL in production. Set the callback base URL in Admin → Settings.');
        }

        return $url;
    }

    public function requiresPayoutApproval(): bool
    {
        return (bool) $this->config->get('astropay.payouts.require_approval', true);
    }

    public function confirmsCallbacksViaQuery(): bool
    {
        return (bool) $this->config->get('astropay.webhooks.confirm_via_query', true);
    }

    public function creditsNetDeposits(): bool
    {
        return $this->config->get('astropay.deposits.wallet_credit', 'gross') === 'net';
    }

    public function notFoundGraceMinutes(): int
    {
        return max(1, (int) $this->config->get('astropay.status_check.not_found_grace_minutes', 15));
    }

    public function statusCheckCooldown(): int
    {
        return max(0, (int) $this->config->get('astropay.status_check.cooldown_seconds', 15));
    }

    /**
     * @return list<string>
     */
    public function allowedWebhookIps(): array
    {
        return $this->settings->webhookAllowedIps();
    }

    public function logger(): LoggerInterface
    {
        $channel = (string) $this->config->get('astropay.log_channel', 'astropay');

        return $this->app->make(LogManager::class)->channel($channel);
    }
}
