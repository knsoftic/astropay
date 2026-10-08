<?php

namespace App\Services\AstroPay;

use App\Enums\AstroPay\Currency;
use App\Exceptions\AstroPay\AstroPayException;
use App\Exceptions\AstroPay\InvalidTransactionStateException;
use App\Models\AstroPayTransaction;
use Illuminate\Validation\ValidationException;

/**
 * UPI Tools → UTR Query & Supplement (INR accounts only).
 */
final class UtrService
{
    public const UTR_PATTERN = '/^[A-Za-z0-9]{10,22}$/';

    public function __construct(
        private readonly AstroPayManager $astropay,
    ) {}

    /**
     * Look up what the UPI channel knows about a UTR. The shape of the
     * returned data depends on the UPI gateway the account is routed through.
     *
     * @return array<string, mixed>
     *
     * @throws AstroPayException
     * @throws ValidationException
     */
    public function query(string $utr): array
    {
        $utr = $this->validUtr($utr);

        return $this->astropay->client(Currency::INR)->queryUtr($utr)->data;
    }

    /**
     * Attach a UTR to an INR deposit whose automatic UTR capture missed it.
     *
     * @return array<string, mixed>
     *
     * @throws AstroPayException
     * @throws ValidationException
     */
    public function supplement(AstroPayTransaction $transaction, string $utr): array
    {
        $utr = $this->validUtr($utr);

        if (! $transaction->canSubmitUtr()) {
            throw new InvalidTransactionStateException('A UTR can only be added to a pending, processing or failed INR deposit.');
        }

        $data = $this->astropay->client(Currency::INR)->supplementUtr($transaction->order_id, $utr)->data;

        $transaction->forceFill(['supplemented_utr' => $utr])->save();

        return $data;
    }

    /**
     * @throws ValidationException
     */
    private function validUtr(string $utr): string
    {
        $utr = strtoupper(preg_replace('/\s+/', '', $utr) ?? '');

        if (preg_match(self::UTR_PATTERN, $utr) !== 1) {
            throw ValidationException::withMessages(['utr' => 'Enter the UPI reference (UTR) from your bank app: 10–22 letters or digits, usually 12 digits.']);
        }

        return $utr;
    }
}
