<?php

namespace App\Services\AstroPay;

enum UpdateOutcome: string
{
    // The transaction moved to a new state.
    case Updated = 'updated';

    // Nothing to do (duplicate callback, stale pending report, ...).
    case Unchanged = 'unchanged';

    // AstroPay reported a status that contradicts a final local state; the
    // transaction was flagged for manual review and left untouched.
    case Conflict = 'conflict';
}
