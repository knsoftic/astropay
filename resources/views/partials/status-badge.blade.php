<span class="badge badge-{{ $transaction->status->tone() }}">{{ $transaction->status->label() }}</span>
@if ($transaction->needs_review && ($showReview ?? false))
    <span class="badge badge-warning">Needs review</span>
@endif
