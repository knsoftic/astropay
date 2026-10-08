<?php

namespace App\Support;

use App\Enums\AstroPay\TransactionStatus;
use App\Models\AstroPayTransaction;
use Illuminate\Support\Carbon;

/**
 * The progress steps shown on a transaction page.
 */
final class TransactionTimeline
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const FAILED = 'failed';

    public const TODO = 'todo';

    /**
     * @return list<array{title: string, state: string, time: Carbon|null}>
     */
    public static function for(AstroPayTransaction $transaction): array
    {
        return $transaction->isDeposit() ? self::deposit($transaction) : self::payout($transaction);
    }

    /**
     * @return list<array{title: string, state: string, time: Carbon|null}>
     */
    private static function deposit(AstroPayTransaction $tx): array
    {
        $status = $tx->status;
        $final = $status->isFinal();
        $failed = in_array($status, [TransactionStatus::Failed, TransactionStatus::Rejected], true);

        return [
            ['title' => 'Order created', 'state' => self::DONE, 'time' => $tx->created_at],
            ['title' => 'Waiting for payment', 'state' => $final ? self::DONE : self::CURRENT, 'time' => $tx->submitted_at],
            [
                'title' => $failed ? ($status === TransactionStatus::Rejected ? 'Not started' : 'Payment failed') : 'Credited to wallet',
                'state' => match (true) {
                    $status === TransactionStatus::Success => self::DONE,
                    $failed => self::FAILED,
                    default => self::TODO,
                },
                'time' => $final ? $tx->completed_at : null,
            ],
        ];
    }

    /**
     * @return list<array{title: string, state: string, time: Carbon|null}>
     */
    private static function payout(AstroPayTransaction $tx): array
    {
        $status = $tx->status;
        $rejectedByAdmin = $tx->rejected_by !== null;
        $sent = $tx->submitted_at !== null && $status !== TransactionStatus::AwaitingApproval;

        $approval = match (true) {
            $rejectedByAdmin => self::FAILED,
            $status === TransactionStatus::AwaitingApproval => self::CURRENT,
            default => self::DONE,
        };

        $sending = match (true) {
            $rejectedByAdmin || $status === TransactionStatus::AwaitingApproval => self::TODO,
            $status === TransactionStatus::Rejected => self::FAILED,
            $status->isFinal() => self::DONE,
            default => self::CURRENT,
        };

        [$paid, $paidTitle] = match ($status) {
            TransactionStatus::Success => [self::DONE, 'Paid out'],
            TransactionStatus::Failed => [self::FAILED, 'Failed, refunded'],
            TransactionStatus::Rejected => [self::FAILED, 'Refunded'],
            default => [self::TODO, 'Paid out'],
        };

        return [
            ['title' => 'Requested', 'state' => self::DONE, 'time' => $tx->created_at],
            ['title' => $rejectedByAdmin ? 'Rejected' : 'Approved', 'state' => $approval, 'time' => $rejectedByAdmin ? $tx->rejected_at : $tx->approved_at],
            ['title' => 'Sent to provider', 'state' => $sending, 'time' => $sent ? $tx->submitted_at : null],
            ['title' => $paidTitle, 'state' => $paid, 'time' => $status->isFinal() ? $tx->completed_at : null],
        ];
    }
}
