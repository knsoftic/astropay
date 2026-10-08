<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Exceptions\AstroPay\GatewayConnectionException;
use App\Exceptions\AstroPay\GatewayRequestException;
use App\Services\AstroPay\Support\Redactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;

/**
 * Low-level client for one AstroPay merchant account (= one currency).
 *
 * Every call is a JSON POST with merchantKey/secretKey in the body. A
 * response is successful only when the envelope's `code` is 1000; AstroPay
 * mirrors 400–599 codes in the HTTP status and uses HTTP 500 otherwise, so
 * the body is parsed regardless of the HTTP status.
 */
final class AstroPayClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
        private readonly Currency $currency,
        private readonly string $merchantKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $queryRetries,
    ) {}

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * POST /v1/payins/create — never retried (AstroPay does not de-duplicate orders).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createDeposit(array $payload): GatewayResponse
    {
        return $this->send('/v1/payins/create', $payload, retryable: false);
    }

    /**
     * POST /v1/payins/query
     */
    public function queryDeposit(string $orderId): GatewayResponse
    {
        return $this->send('/v1/payins/query', ['orderId' => $orderId], retryable: true);
    }

    /**
     * POST /v1/payouts/create — never retried (a retry could pay out twice).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createPayout(array $payload): GatewayResponse
    {
        return $this->send('/v1/payouts/create', $payload, retryable: false);
    }

    /**
     * POST /v1/payouts/query
     */
    public function queryPayout(string $orderId): GatewayResponse
    {
        return $this->send('/v1/payouts/query', ['orderId' => $orderId], retryable: true);
    }

    /**
     * POST /v1/account/balance
     */
    public function balance(): GatewayResponse
    {
        return $this->send('/v1/account/balance', [], retryable: true);
    }

    /**
     * POST /v1/utr/query (UPI/INR accounts only).
     */
    public function queryUtr(string $utr): GatewayResponse
    {
        return $this->send('/v1/utr/query', ['utr' => $utr], retryable: true);
    }

    /**
     * POST /v1/utr/supplement (UPI/INR accounts only).
     */
    public function supplementUtr(string $orderId, string $utr): GatewayResponse
    {
        return $this->send('/v1/utr/supplement', ['orderId' => $orderId, 'utr' => $utr], retryable: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws GatewayRequestException
     * @throws GatewayConnectionException
     */
    private function send(string $endpoint, array $payload, bool $retryable): GatewayResponse
    {
        $body = ['merchantKey' => $this->merchantKey, 'secretKey' => $this->secretKey] + $payload;
        $attempts = $retryable ? 1 + max(0, $this->queryRetries) : 1;
        $context = ['currency' => $this->currency->value, 'endpoint' => $endpoint];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $isLastAttempt = $attempt === $attempts;

            $this->logger->info('AstroPay request', $context + ['attempt' => $attempt, 'payload' => Redactor::redact($body)]);

            try {
                $response = $this->request()->post($endpoint, $body);
            } catch (ConnectionException $e) {
                $this->logger->warning('AstroPay connection error', $context + ['attempt' => $attempt, 'error' => $e->getMessage()]);

                if (! $isLastAttempt) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new GatewayConnectionException('Could not reach AstroPay: '.$e->getMessage(), null, $e);
            } catch (RequestException $e) {
                // Only thrown if a global "throw" option is configured; treat like any other response.
                $response = $e->response;
            }

            $json = $this->decode($response);

            $this->logger->info('AstroPay response', $context + [
                'attempt' => $attempt,
                'http_status' => $response->status(),
                'body' => $json !== null ? Redactor::redact($json) : mb_substr($response->body(), 0, 500),
            ]);

            if ($json === null) {
                $error = new GatewayConnectionException(sprintf('AstroPay returned an unexpected response (HTTP %d).', $response->status()), $response->status());

                if (! $isLastAttempt && $response->status() >= 500) {
                    $this->backoff($attempt);

                    continue;
                }

                throw $error;
            }

            $code = (int) $json['code'];
            $message = is_scalar($json['msg'] ?? null) ? (string) $json['msg'] : '';

            if ($code === 1000) {
                $data = $json['data'] ?? [];

                return new GatewayResponse(is_array($data) ? $data : [], $message, $response->status(), $json);
            }

            $error = new GatewayRequestException($code, $message, $response->status(), $json);

            if ($retryable && $error->isRetryable() && ! $isLastAttempt) {
                $this->backoff($attempt);

                continue;
            }

            $this->logger->warning('AstroPay rejected request', $context + ['code' => $code, 'msg' => $message]);

            throw $error;
        }

        // Unreachable: the loop either returns or throws.
        throw new GatewayConnectionException('AstroPay request failed.');
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->asJson()
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withUserAgent('GPay-AstroPay-Laravel/1.0');
    }

    /**
     * @return array<string, mixed>|null the envelope, or null when the body is not one
     */
    private function decode(Response $response): ?array
    {
        $json = json_decode($response->body(), true);

        if (! is_array($json) || ! array_key_exists('code', $json) || ! is_numeric($json['code'])) {
            return null;
        }

        return $json;
    }

    private function backoff(int $attempt): void
    {
        Sleep::usleep(min(250_000 * (2 ** ($attempt - 1)), 2_000_000));
    }
}
