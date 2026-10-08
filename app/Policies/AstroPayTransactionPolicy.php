<?php

namespace App\Policies;

use App\Models\AstroPayTransaction;
use App\Models\User;

class AstroPayTransactionPolicy
{
    public function view(User $user, AstroPayTransaction $transaction): bool
    {
        return $user->isAdmin() || $transaction->user_id === $user->getKey();
    }

    /**
     * Owners may trigger a status check, continue a payment or submit a UTR.
     */
    public function interact(User $user, AstroPayTransaction $transaction): bool
    {
        return $transaction->user_id === $user->getKey();
    }

    public function manage(User $user, AstroPayTransaction $transaction): bool
    {
        return $user->isAdmin();
    }
}
