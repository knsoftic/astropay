<?php

namespace Tests\Unit\AstroPay;

use App\Services\AstroPay\Support\Signature;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    public function test_it_builds_the_documented_sign_string(): void
    {
        $payload = ['orderId' => 'ORD-1001', 'amount' => '500.0000', 'commission' => '35.0000', 'status' => '10', 'utr' => '437558231943'];

        $this->assertSame(
            'amount=500.0000&commission=35.0000&orderId=ORD-1001&status=10&utr=437558231943&secret=Sk_test_9f8e7d6c',
            Signature::signString($payload, 'Sk_test_9f8e7d6c'),
        );

        $this->assertSame(
            strtoupper(md5('amount=500.0000&commission=35.0000&orderId=ORD-1001&status=10&utr=437558231943&secret=Sk_test_9f8e7d6c')),
            Signature::forCallback($payload, 'Sk_test_9f8e7d6c'),
        );
    }

    public function test_empty_and_null_values_are_skipped(): void
    {
        $payload = ['orderId' => 'ORD-1', 'amount' => '100.0000', 'commission' => null, 'status' => '9', 'utr' => ''];

        $this->assertSame('amount=100.0000&orderId=ORD-1&status=9&secret=s', Signature::signString($payload, 's'));
    }

    public function test_only_the_documented_fields_are_signed(): void
    {
        $payload = ['orderId' => 'ORD-1', 'amount' => '1', 'commission' => '0', 'status' => '10', 'utr' => 'U1'];
        $withExtras = $payload + ['remark' => 'insufficient funds', 'sign' => 'X', 'extra' => 'y'];

        $this->assertSame(Signature::forCallback($payload, 'k'), Signature::forCallback($withExtras, 'k'));
    }

    public function test_verification_is_exact_and_case_sensitive(): void
    {
        $payload = ['orderId' => 'ORD-1', 'amount' => '1.0000', 'commission' => '0.1000', 'status' => '10', 'utr' => 'U1'];
        $payload['sign'] = Signature::forCallback($payload, 'secret');

        $this->assertTrue(Signature::verifyCallback($payload, 'secret'));
        $this->assertFalse(Signature::verifyCallback($payload, 'other-secret'));
        $this->assertFalse(Signature::verifyCallback(['sign' => strtolower($payload['sign'])] + $payload, 'secret'));
        $this->assertFalse(Signature::verifyCallback(['amount' => '2.0000'] + $payload, 'secret'));
        $this->assertFalse(Signature::verifyCallback(['sign' => ''] + $payload, 'secret'));
        $this->assertFalse(Signature::verifyCallback($payload, ''));
    }
}
