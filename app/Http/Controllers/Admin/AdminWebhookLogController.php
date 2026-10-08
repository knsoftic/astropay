<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AstroPayWebhookLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminWebhookLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'failed' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $logs = AstroPayWebhookLog::query()
            ->with('transaction:id,uuid')
            ->when($request->boolean('failed'), fn ($q) => $q->where('http_status', '>=', 300))
            ->when(filled($filters['q'] ?? null), fn ($q) => $q->where('order_id', trim((string) $filters['q'])))
            ->latest('id')
            ->simplePaginate(50)
            ->withQueryString();

        return view('admin.webhooks', ['logs' => $logs, 'filters' => $filters]);
    }
}
