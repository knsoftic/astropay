<?php

namespace Tests\Unit\AstroPay;

use App\Enums\AstroPay\GatewayStatus;
use App\Services\AstroPay\Support\Amount;
use App\Services\AstroPay\Support\CallbackPayload;
use App\Services\AstroPay\Support\PhoneNumber;
use App\Services\AstroPay\Support\Redactor;
use App\Services\AstroPay\Support\TronAddress;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_tron_addresses_are_checksum_validated(): void
    {
        // USDT TRC20 contract address (a valid mainnet address).
        $this->assertTrue(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));

        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u'), 'bad checksum');
        $this->assertFalse(TronAddress::isValid('XR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'), 'wrong prefix');
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6'), 'too short');
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj0t'), 'invalid base58 char');
        $this->assertFalse(TronAddress::isValid('0x742d35Cc6634C0532925a3b844Bc454e4438f44e'), 'ethereum address');
    }

    public function test_phone_numbers_are_normalised_per_market(): void
    {
        $this->assertSame('9876543210', PhoneNumber::india('+91 98765 43210'));
        $this->assertSame('9876543210', PhoneNumber::india('09876543210'));
        $this->assertNull(PhoneNumber::india('1234567890'));

        $this->assertSame('03001234567', PhoneNumber::pakistan('+92 300 1234567'));
        $this->assertSame('03001234567', PhoneNumber::pakistan('0092-300-1234567'));
        $this->assertSame('03001234567', PhoneNumber::pakistan('3001234567'));
        $this->assertNull(PhoneNumber::pakistan('0421234567'));

        $this->assertSame('01712345678', PhoneNumber::bangladesh('+880 1712-345678'));
        $this->assertSame('01712345678', PhoneNumber::bangladesh('1712345678'));
        $this->assertNull(PhoneNumber::bangladesh('01212345678'));

        $this->assertSame('15551234567', PhoneNumber::generic('+1 (555) 123-4567'));
        $this->assertNull(PhoneNumber::generic('abc123'));
    }

    public function test_amount_helpers_are_exact(): void
    {
        $this->assertSame('500.00', Amount::toRequest('500'));
        $this->assertSame('0.3000', Amount::plus('0.1', '0.2'));
        $this->assertSame('-200.0000', Amount::negate('200'));
        $this->assertTrue(Amount::equals('500.00', '500.0000'));
        $this->assertTrue(Amount::equals(500, '500.0000'));
        $this->assertFalse(Amount::equals('500.01', '500'));
        $this->assertSame('1,234,567.89', Amount::format('1234567.891'));
        $this->assertSame('214.0000', Amount::toStorage(214));
        $this->assertNull(Amount::toStorage('abc'));
        $this->assertNull(Amount::toStorage('1e5'));
    }

    public function test_json_callback_keeps_numeric_literals_verbatim(): void
    {
        $raw = '{"orderId":"ORD-1","amount":500.0000,"commission":35.5,"status":10,"utr":"437558231943","sign":"ABC"}';

        $payload = CallbackPayload::fromJson($raw);

        $this->assertSame('500.0000', $payload['amount']);
        $this->assertSame('35.5', $payload['commission']);
        $this->assertSame('10', $payload['status']);
        $this->assertSame('437558231943', $payload['utr']);
    }

    public function test_malformed_json_callback_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CallbackPayload::fromJson('{"orderId":');
    }

    public function test_gateway_status_accepts_numbers_and_strings(): void
    {
        $this->assertSame(GatewayStatus::Success, GatewayStatus::fromMixed('10'));
        $this->assertSame(GatewayStatus::Success, GatewayStatus::fromMixed(10));
        $this->assertSame(GatewayStatus::Failed, GatewayStatus::fromMixed(' 9 '));
        $this->assertSame(GatewayStatus::Pending, GatewayStatus::fromMixed(1));
        $this->assertNull(GatewayStatus::fromMixed('2'));
        $this->assertNull(GatewayStatus::fromMixed('success'));
        $this->assertNull(GatewayStatus::fromMixed(null));
    }

    public function test_redactor_hides_credentials_and_masks_personal_data(): void
    {
        $redacted = Redactor::redact([
            'merchantKey' => 'mk_live',
            'secretKey' => 'sk_live',
            'account' => '03001234567',
            'orderId' => 'ORD-1',
        ]);

        $this->assertSame('[redacted]', $redacted['merchantKey']);
        $this->assertSame('[redacted]', $redacted['secretKey']);
        $this->assertSame('03*******67', $redacted['account']);
        $this->assertSame('ORD-1', $redacted['orderId']);
    }
}
