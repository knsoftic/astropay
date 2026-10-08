@extends('layouts.app')

@section('title', 'Admin · Callbacks')

@section('content')
    <h1>Callbacks</h1>
    @include('admin._nav')

    <form method="GET" action="{{ route('admin.webhooks.index') }}" class="filters card">
        <div class="field">
            <label for="q">Order ID</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="64">
        </div>
        <div class="field">
            <label><input type="checkbox" name="failed" value="1" @checked(request()->boolean('failed'))> Rejected only</label>
        </div>
        <button type="submit" class="btn">Filter</button>
    </form>

    <div class="card">
        @if ($logs->isEmpty())
            <p class="muted">No callbacks received.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Received</th><th>Endpoint</th><th>Order ID</th><th>IP</th><th>Signature</th><th>Outcome</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $log->type }}/{{ $log->currency }}</td>
                            <td class="mono">
                                @if ($log->transaction)
                                    <a href="{{ route('admin.transactions.show', $log->transaction->uuid) }}">{{ $log->order_id }}</a>
                                @else
                                    {{ $log->order_id ?? '—' }}
                                @endif
                            </td>
                            <td class="mono">{{ $log->ip }}</td>
                            <td>{{ $log->signature_valid ? 'valid' : 'invalid' }}</td>
                            <td><span @class(['badge', 'badge-success' => $log->http_status < 300, 'badge-danger' => $log->http_status >= 300])>{{ $log->outcome }} · {{ $log->http_status }}</span></td>
                            <td>{{ $log->message }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $logs])
        @endif
    </div>
@endsection
