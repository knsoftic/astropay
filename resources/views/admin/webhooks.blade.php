@extends('layouts.app')

@section('title', 'Callbacks')
@section('subtitle', 'Every notification AstroPay sent to this site')

@section('content')
    <form method="GET" action="{{ route('admin.webhooks.index') }}" class="card" style="margin-bottom:20px">
        <div class="filters">
            <div class="field grow">
                <label for="q">Order ID</label>
                <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="64" placeholder="Exact order ID">
            </div>
            <div class="field" style="flex:0 0 auto;min-width:0;padding-bottom:11px">
                <label class="checkbox"><input type="checkbox" name="failed" value="1" @checked(request()->boolean('failed'))> Rejected only</label>
            </div>
            <div class="row" style="gap:8px">
                <button type="submit" class="btn btn-primary"><x-icon name="filter" /> Filter</button>
                <a class="btn btn-ghost" href="{{ route('admin.webhooks.index') }}">Reset</a>
            </div>
        </div>
    </form>

    <div class="card card-flush">
        @if ($logs->isEmpty())
            <x-empty icon="activity" title="No callbacks yet" text="Callbacks appear here as soon as AstroPay notifies this site about an order." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Received</th><th>Endpoint</th><th>Order ID</th><th>IP</th><th>Signature</th><th>Outcome</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="nowrap">
                                <div>{{ $log->created_at->format('d M Y') }}</div>
                                <div class="cell-sub">{{ $log->created_at->format('H:i:s') }}</div>
                            </td>
                            <td><span class="row" style="gap:8px"><x-coin :currency="$log->currency" size="sm" /> {{ $log->type }}</span></td>
                            <td class="mono">
                                @if ($log->transaction)
                                    <a href="{{ route('admin.transactions.show', $log->transaction->uuid) }}">{{ $log->order_id }}</a>
                                @else
                                    {{ $log->order_id ?? '—' }}
                                @endif
                            </td>
                            <td class="mono">{{ $log->ip }}</td>
                            <td>@if ($log->signature_valid)<span class="badge badge-success">valid</span>@else<span class="badge badge-danger">invalid</span>@endif</td>
                            <td><span @class(['badge', 'no-dot', 'badge-success' => $log->http_status < 300, 'badge-danger' => $log->http_status >= 300])>{{ $log->outcome }} · {{ $log->http_status }}</span></td>
                            <td class="small soft">{{ $log->message }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $logs])
        @endif
    </div>
@endsection
