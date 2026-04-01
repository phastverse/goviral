<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Logged;
use App\Services\KorapayService;
use App\Services\WalletService;
use App\Traits\ChecksPendingDeposits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ResellerWalletController extends Controller
{
    use ChecksPendingDeposits;

    protected KorapayService $korapayService;

    public function __construct(KorapayService $korapayService)
    {
        $this->korapayService = $korapayService;
    }

    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    public function index()
    {
        $user     = Auth::user();
        $reseller = $this->currentReseller();

        // Check pending deposits exactly like your main WalletController does
        $this->checkUserPendingDeposits($user, 5);

        // Transaction history — scoped to this user only
        $transactions = Wallet::where('user_id', $user->id)
            ->latest()
            ->paginate(20);

        return view('reseller.wallet.index', compact('reseller', 'transactions'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $user     = Auth::user();
        $reseller = $this->currentReseller();
        $amount   = $request->amount;

        // Callback URL must go back to the reseller subdomain,
        // not your main domain — so we build it from the current host
        $redirectUrl = url('/wallet/callback');

        $result = $this->korapayService->initializeTransaction($amount, $user, $redirectUrl);

        if ($result['success']) {
            // Create a pending wallet entry before redirecting
            WalletService::initiateDeposit(
                $user,
                $amount,
                $result['reference'],
                'Korapay',
                "Top-up via {$reseller->panel_name} (Ref: {$result['reference']})"
            );

            session(['pending_payment_reference' => $result['reference']]);

            return redirect()->away($result['data']['checkout_url']);
        }

        return redirect()->back()->with('alert', [
            'type'    => 'error',
            'message' => $result['message'],
        ]);
    }

    public function callback(Request $request)
    {
        $user     = Auth::user();
        $reseller = $this->currentReseller();

        $reference = $request->query('reference')
                  ?? session('pending_payment_reference');

        if (!$reference) {
            return redirect()->route('reseller.wallet.index')->with('alert', [
                'type'    => 'error',
                'message' => 'Invalid payment reference.',
            ]);
        }

        // Check if already processed
        $loggedEntry = Logged::where('reference', $reference)->first();

        if (!$loggedEntry) {
            return redirect()->route('reseller.wallet.index')->with('alert', [
                'type'    => 'error',
                'message' => 'Transaction record not found.',
            ]);
        }

        // Already fully processed — skip everything
        $walletEntry = Wallet::where('reference', $reference)->first();
        if ($walletEntry && $walletEntry->status === 'success') {
            session()->forget('pending_payment_reference');

            return redirect('/wallet')->with('alert', [
                'type'    => 'success',
                'message' => 'Payment already processed. Balance: ₦' . number_format($user->balance, 2),
            ]);
        }

        // Verify with Korapay
        $result = $this->korapayService->verifyTransaction($reference);

        if ($result['success']) {
            WalletService::deposit(
                $user,
                $result['amount'],
                $reference,
                'Korapay',
                "Top-up via {$reseller->panel_name} (Ref: {$reference})"
            );

            session()->forget('pending_payment_reference');

            return redirect('/wallet')->with('alert', [
                'type'    => 'success',
                'message' => 'Wallet topped up successfully with ₦' . number_format($result['amount'], 2),
            ]);
        }

        // Verification failed — mark the pending entry as failed
        WalletService::markDepositFailed($reference);

        return redirect()->route('reseller.wallet.index')->with('alert', [
            'type'    => 'error',
            'message' => 'Payment verification failed. Contact support if your account was debited.',
        ]);
    }
}