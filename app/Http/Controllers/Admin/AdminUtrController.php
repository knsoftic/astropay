<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AstroPay\Currency;
use App\Exceptions\AstroPay\AstroPayException;
use App\Http\Controllers\Controller;
use App\Models\AstroPayTransaction;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\UtrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UPI Tools → UTR Query & Supplement (INR account only).
 */
class AdminUtrController extends Controller
{
    public function index(AstroPayManager $astropay): View
    {
        return view('admin.utr', [
            'enabled' => $astropay->hasCredentials(Currency::INR),
        ]);
    }

    public function query(Request $request, UtrService $utr): RedirectResponse
    {
        $data = $request->validate(['utr' => ['required', 'string', 'max:32']]);

        try {
            $result = $utr->query($data['utr']);
        } catch (AstroPayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->withInput()->with('utr_result', ['action' => 'query', 'utr' => $data['utr'], 'data' => $result]);
    }

    public function supplement(Request $request, UtrService $utr): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
            'utr' => ['required', 'string', 'max:32'],
        ]);

        $transaction = AstroPayTransaction::query()->where('order_id', trim($data['order_id']))->first();

        if ($transaction === null) {
            return back()->withInput()->withErrors(['order_id' => 'No transaction with this order ID.']);
        }

        if ($transaction->currency !== Currency::INR || ! $transaction->isDeposit()) {
            return back()->withInput()->withErrors(['order_id' => 'UTR supplement only applies to INR deposits.']);
        }

        try {
            $result = $utr->supplement($transaction, $data['utr']);
        } catch (AstroPayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('utr_result', [
            'action' => 'supplement',
            'utr' => $data['utr'],
            'transaction' => $transaction->uuid,
            'data' => $result,
        ]);
    }
}
