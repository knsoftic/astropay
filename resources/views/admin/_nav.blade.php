<div class="actions" style="margin-bottom:20px">
    <a @class(['btn', 'btn-secondary' => ! request()->routeIs('admin.dashboard', 'admin.balance')]) href="{{ route('admin.dashboard') }}">Overview</a>
    <a @class(['btn', 'btn-secondary' => ! request()->routeIs('admin.transactions.*')]) href="{{ route('admin.transactions.index') }}">Transactions</a>
    <a class="btn btn-secondary" href="{{ route('admin.transactions.index', ['type' => 'payout', 'status' => 'awaiting_approval']) }}">Approvals</a>
    <a class="btn btn-secondary" href="{{ route('admin.transactions.index', ['review' => 1]) }}">Reviews</a>
    <a @class(['btn', 'btn-secondary' => ! request()->routeIs('admin.utr.*')]) href="{{ route('admin.utr.index') }}">UTR tools</a>
    <a @class(['btn', 'btn-secondary' => ! request()->routeIs('admin.webhooks.*')]) href="{{ route('admin.webhooks.index') }}">Callbacks</a>
    <a @class(['btn', 'btn-secondary' => ! request()->routeIs('admin.settings.*')]) href="{{ route('admin.settings.edit') }}">Settings</a>
</div>
