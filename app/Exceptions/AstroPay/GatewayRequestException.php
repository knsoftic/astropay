<?php

namespace App\Exceptions\AstroPay;

/**
 * AstroPay answered with a well-formed envelope whose `code` is not 1000.
 */
class GatewayRequestException extends AstroPayException
{
    /**
     * Codes after which AstroPay has definitely not created the order.
     */
    private const DEFINITIVE_CODES = [400, 401, 403, 404];

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $gatewayCode,
        public readonly string $gatewayMessage,
        public readonly int $httpStatus,
        public readonly array $body = [],
    ) {
        parent::__construct(sprintf('AstroPay error %d: %s', $gatewayCode, $gatewayMessage !== '' ? $gatewayMessage : 'no message'), $gatewayCode);
    }

    /**
     * 500/503 (and any undocumented code) on a create call means the order
     * may or may not exist; it has to be resolved with a status query.
     */
    public function isOutcomeUnknown(): bool
    {
        return ! in_array($this->gatewayCode, self::DEFINITIVE_CODES, true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->gatewayCode, [500, 503], true);
    }

    public function isNotFound(): bool
    {
        return $this->gatewayCode === 404;
    }

    public function userMessage(): string
    {
        return match ($this->gatewayCode) {
            // 400 messages are validation messages such as
            // "Deposit amount must not be lower than: 100".
            400 => $this->gatewayMessage !== '' && preg_match('/\p{Han}/u', $this->gatewayMessage) !== 1
                ? $this->gatewayMessage
                : 'The payment request was rejected. Please check the details and try again.',
            404 => 'The payment order could not be found.',
            default => parent::userMessage(),
        };
    }
}
