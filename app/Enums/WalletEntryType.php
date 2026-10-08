<?php

namespace App\Enums;

enum WalletEntryType: string
{
    // Successful deposit credited to the wallet.
    case DepositCredit = 'deposit_credit';

    // Amount held when a withdrawal is requested.
    case PayoutHold = 'payout_hold';

    // Held amount returned because the withdrawal failed or was rejected.
    case PayoutRefund = 'payout_refund';

    // Admin re-debit of a refund after AstroPay later reported the payout as paid.
    case PayoutReclaim = 'payout_reclaim';

    public function label(): string
    {
        return match ($this) {
            self::DepositCredit => 'Deposit',
            self::PayoutHold => 'Withdrawal',
            self::PayoutRefund => 'Withdrawal refund',
            self::PayoutReclaim => 'Refund reversal',
        };
    }
}
