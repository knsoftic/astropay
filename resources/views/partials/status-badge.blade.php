<span @class(['badge', 'badge-'.$transaction->status->tone(), 'badge-pulse' => in_array($transaction->status->value, ['pending', 'unknown', 'initiated'], true)])>{{ $transaction->status->label() }}</span>
@if ($transaction->needs_review && ($showReview ?? false))
    <span class="badge badge-warning no-dot">Needs review</span>
@endif
