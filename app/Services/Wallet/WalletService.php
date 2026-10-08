<?php

namespace App\Services\Wallet;

use App\Enums\AstroPay\Currency;
use App\Enums\WalletEntryType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\AstroPayTransaction;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Services\AstroPay\Support\Amount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Per-user, per-currency balances with an append-only ledger.
 *
 * Every mutation must run inside a database transaction; the wallet row is
 * locked for update and an entry is written with the resulting balance. The
 * unique (transaction_id, type) index makes credits/holds/refunds idempotent.
 */
final class WalletService
{
    /**
     * Fetch (or create) the wallet and lock it for the current transaction.
     */
    public function lockedWallet(int $userId, Currency $currency): Wallet
    {
        $this->assertInTransaction();

        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->lockForUpdate()
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            // Savepoint so a concurrent insert does not abort the outer transaction.
            DB::transaction(fn () => Wallet::query()->create([
                'user_id' => $userId,
                'currency' => $currency->value,
                'balance' => '0',
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another request created it first.
        }

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function balance(int $userId, Currency $currency): string
    {
        $balance = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->value('balance');

        return Amount::toStorage($balance ?? '0') ?? '0.0000';
    }

    /**
     * Credit the wallet. Returns the existing entry if this transaction was
     * already credited with this entry type.
     */
    public function credit(Wallet $wallet, string $amount, WalletEntryType $type, ?AstroPayTransaction $transaction, string $description, ?int $createdBy = null): WalletEntry
    {
        if (! Amount::isPositive($amount)) {
            throw new LogicException('Wallet credits must be positive.');
        }

        return $this->apply($wallet, Amount::toStorage($amount), $type, $transaction, $description, $createdBy, allowNegative: true);
    }

    /**
     * Debit the wallet.
     *
     * @throws InsufficientWalletBalanceException unless $allowNegative is true
     */
    public function debit(Wallet $wallet, string $amount, WalletEntryType $type, ?AstroPayTransaction $transaction, string $description, ?int $createdBy = null, bool $allowNegative = false): WalletEntry
    {
        if (! Amount::isPositive($amount)) {
            throw new LogicException('Wallet debits must be positive.');
        }

        return $this->apply($wallet, Amount::negate($amount), $type, $transaction, $description, $createdBy, $allowNegative);
    }

    public function hasEntry(AstroPayTransaction $transaction, WalletEntryType $type): bool
    {
        return WalletEntry::query()
            ->where('transaction_id', $transaction->getKey())
            ->where('type', $type->value)
            ->exists();
    }

    private function apply(Wallet $wallet, string $signedAmount, WalletEntryType $type, ?AstroPayTransaction $transaction, string $description, ?int $createdBy, bool $allowNegative): WalletEntry
    {
        $this->assertInTransaction();

        if ($transaction !== null) {
            $existing = WalletEntry::query()
                ->where('transaction_id', $transaction->getKey())
                ->where('type', $type->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $newBalance = Amount::plus($wallet->balance, $signedAmount);

        if (! $allowNegative && Amount::compare($newBalance, '0') < 0) {
            throw new InsufficientWalletBalanceException(Amount::toStorage($wallet->balance) ?? '0', Amount::negate($signedAmount));
        }

        $wallet->balance = $newBalance;
        $wallet->save();

        return $wallet->entries()->create([
            'transaction_id' => $transaction?->getKey(),
            'type' => $type->value,
            'amount' => $signedAmount,
            'balance_after' => $newBalance,
            'description' => mb_substr($description, 0, 255),
            'created_by' => $createdBy,
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Wallet operations must run inside a database transaction.');
        }
    }
}
