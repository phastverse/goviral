<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Logged;
use App\Services\ProviderService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Notifications\OrderPlaced;
use App\Services\TikTokEventService;

class OrderController extends Controller
{
    protected ProviderService $providerService;
    protected TikTokEventService $tiktokEventService;

    public function __construct(ProviderService $providerService, TikTokEventService $tiktokEventService)
    {
        $this->providerService = $providerService;
        $this->tiktokEventService = $tiktokEventService;
    }

    // ─── New Order Form ───────────────────────────────────────────────────────

    public function create()
    {
        $services = $this->providerService->getServices();

        $platformIcons = [
            'Instagram' => 'fa-brands fa-instagram',
            'TikTok'    => 'fa-brands fa-tiktok',
            'Facebook'  => 'fa-brands fa-facebook',
            'Telegram'  => 'fa-brands fa-telegram',
            'Twitter'   => 'fa-brands fa-twitter',
            'YouTube'   => 'fa-brands fa-youtube',
            'Spotify'   => 'fa-brands fa-spotify',
            'Pinterest' => 'fa-brands fa-pinterest',
            'LinkedIn'  => 'fa-brands fa-linkedin',
            'Discord'   => 'fa-brands fa-discord',
            'Snapchat'  => 'fa-brands fa-snapchat',
            'Twitch'    => 'fa-brands fa-twitch',
            'WhatsApp'  => 'fa-brands fa-whatsapp',
            'Website'   => 'fa-solid fa-globe',
        ];

        $groupedServices = [];

        foreach ($services as $service) {
            $serviceName = $service['name'];
            $platform    = 'Website';

            if      (stripos($serviceName, 'Instagram') !== false) $platform = 'Instagram';
            elseif  (stripos($serviceName, 'TikTok')    !== false) $platform = 'TikTok';
            elseif  (stripos($serviceName, 'Facebook')  !== false) $platform = 'Facebook';
            elseif  (stripos($serviceName, 'Telegram')  !== false) $platform = 'Telegram';
            elseif  (stripos($serviceName, 'Twitter')   !== false) $platform = 'Twitter';
            elseif  (stripos($serviceName, 'Youtube') !== false || stripos($serviceName, 'YouTube') !== false) $platform = 'YouTube';
            elseif  (stripos($serviceName, 'Spotify')   !== false) $platform = 'Spotify';
            elseif  (stripos($serviceName, 'Pinterest') !== false) $platform = 'Pinterest';
            elseif  (stripos($serviceName, 'Linkedin')  !== false) $platform = 'LinkedIn';
            elseif  (stripos($serviceName, 'Discord')   !== false) $platform = 'Discord';
            elseif  (stripos($serviceName, 'Snapchat')  !== false) $platform = 'Snapchat';
            elseif  (stripos($serviceName, 'Twitch')    !== false) $platform = 'Twitch';
            elseif  (stripos($serviceName, 'WhatsApp')  !== false) $platform = 'WhatsApp';

            $icon = $platformIcons[$platform] ?? $platformIcons['Website'];

            if (!isset($groupedServices[$platform])) {
                $groupedServices[$platform] = ['icon' => $icon, 'services' => []];
            }

            $groupedServices[$platform]['services'][] = $service;
        }

        return view('order.new', compact('groupedServices'));
    }

    // ─── Store / Process Order ────────────────────────────────────────────────

    public function store(Request $request)
    {
        $request->validate([
            'service_id'   => 'required|integer',
            'service_name' => 'required|string',
            'link'         => 'required|url',
            'quantity'     => 'required|integer|min:10',
            'charge'       => 'required|numeric|min:0',
        ]);

        $user           = Auth::user();
        $orderReference = 'ORD-' . strtoupper(Str::random(8));

        // ── 1. Server-side charge calculation (secure) ──────────────────────
        $services    = $this->providerService->getServices();
        $serviceRate = null;

        foreach ($services as $service) {
            if ((int) $service['service'] === (int) $request->service_id) {
                $serviceRate = (float) $service['rate'];
                break;
            }
        }

        if ($serviceRate === null || $serviceRate <= 0) {
            $this->logOrderAction(
                'order_failed', $orderReference, 0, 'failed',
                'Service not found or invalid rate from API',
                ['service_id' => $request->service_id], null, 'Invalid service'
            );

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Invalid service selected. Please try again.',
            ]);
        }

        $markedUpRate = \App\Services\PricingService::calculatePrice($serviceRate, $request->service_name);
        $serverCharge = round(($request->quantity / 1000) * $markedUpRate, 2);

        if ((float) $request->charge !== $serverCharge) {
            $this->logOrderAction(
                'order_initiated', $orderReference, $serverCharge, 'failed',
                'Charge mismatch detected',
                [
                    'service_id'       => $request->service_id,
                    'service_name'     => $request->service_name,
                    'link'             => $request->link,
                    'quantity'         => $request->quantity,
                    'server_charge'    => $serverCharge,
                    'frontend_charge'  => $request->charge,
                ],
                ['status' => 'mismatch_logged']
            );
        }

        $charge = $serverCharge;

        // ── 2. Log order initiation ─────────────────────────────────────────
        $this->logOrderAction(
            'order_initiated', $orderReference, $charge, 'success',
            'Order initiated by user',
            [
                'service_id'     => $request->service_id,
                'service_name'   => $request->service_name,
                'link'           => $request->link,
                'quantity'       => $request->quantity,
                'charge'         => $charge,
                'frontend_charge' => $request->charge,
            ],
            ['status' => 'initiated']
        );

        // ── 3. Duplicate prevention ─────────────────────────────────────────
        $recentDuplicate = \App\Models\Order::where('user_id', $user->id)
            ->where('service_id', $request->service_id)
            ->where('link', $request->link)
            ->where('quantity', $request->quantity)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->where('created_at', '>=', now()->subMinutes(3))
            ->lockForUpdate()
            ->first();

        if ($recentDuplicate) {
            $this->logOrderAction(
                'order_rejected', $orderReference, $charge, 'failed',
                'Duplicate order detected',
                ['duplicate_of' => $recentDuplicate->id, 'service_id' => $request->service_id],
                null, 'Duplicate order'
            );

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'A similar order was recently placed. Please wait a few minutes.',
            ]);
        }

        // ── 4. Balance check ────────────────────────────────────────────────
        if ($user->balance < $charge) {
            $this->logOrderAction(
                'order_failed', $orderReference, $charge, 'failed',
                'Insufficient wallet balance',
                ['balance' => $user->balance, 'required' => $charge],
                null, 'Insufficient funds'
            );

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Insufficient wallet balance.',
            ]);
        }

        // ── 5. Main transaction ─────────────────────────────────────────────
        try {
            // Deduct wallet
            WalletService::withdraw(
                $user, $charge, $orderReference,
                'Order Debit', "Order for: {$request->service_name}"
            );

            $this->logOrderAction(
                'wallet_debited', $orderReference, $charge, 'success',
                'Wallet debited for order',
                ['previous_balance' => $user->balance + $charge],
                ['new_balance' => $user->balance]
            );

            // ── 6. Place order via ProviderService (auto-fallback) ──────────
            $result = $this->providerService->placeOrder(
                $request->service_id,
                $request->link,
                $request->quantity
            );

            // ── 7. Handle result ────────────────────────────────────────────
            if ($result !== null) {
                $apiResponse      = $result['response'];
                $chosenProvider   = $result['provider'];
                $attemptsNeeded   = $result['attempted'];

                $profit            = \App\Services\PricingService::calculateProfit(
                    $charge, $request->quantity, $request->service_name
                );
                $markupPercentage  = \App\Services\PricingService::getMarkupPercentage($request->service_name);

                $order = Order::create([
                    'user_id'          => $user->id,
                    'service_id'       => $request->service_id,
                    'service_name'     => $request->service_name,
                    'link'             => $request->link,
                    'quantity'         => $request->quantity,
                    'charge'           => $charge,
                    'status'           => 'processing',
                    'api_order_id'     => $apiResponse['order'],
                    'api_response'     => json_encode($apiResponse),
                    'profit'           => $profit,
                    'markup_percentage' => $markupPercentage,
                    'provider_id'      => $chosenProvider->id,  // ← track which provider
                ]);

                $this->logOrderAction(
                    'order_success', $orderReference, $charge, 'success',
                    "Order placed via [{$chosenProvider->name}] after {$attemptsNeeded} attempt(s)",
                    [
                        'order_id'     => $order->id,
                        'api_order_id' => $apiResponse['order'],
                        'provider'     => $chosenProvider->name,
                        'attempts'     => $attemptsNeeded,
                    ],
                    $apiResponse
                );

                // ── Send TikTok Purchase Event────────────────
                try {
                    $this->tiktokEventService->sendPurchaseEvent($order, $user, $request);
                } catch (\Exception $e) {
                    \Log::error('TikTok event failed but order completed: ' . $e->getMessage());
                }


                try {
                    $user->notify(new OrderPlaced($order));
                } catch (\Exception $e) {
                    \Log::error('Failed to send order notification: ' . $e->getMessage());
                }

                \App\Services\ReferralService::markOrder($user);

                return redirect()->route('orders.index')->with('alert', [
                    'type'    => 'success',
                    'message' => 'Order placed successfully! Order ID: ' . $apiResponse['order'],
                ]);

            } else {
                // All providers failed → refund
                $this->logOrderAction(
                    'api_failed', $orderReference, $charge, 'failed',
                    'All providers failed for this order',
                    ['service_id' => $request->service_id, 'link' => $request->link],
                    null, 'All providers failed'
                );

                $refundResult = WalletService::refund($user, $charge, 'Order Failed', $orderReference);

                $this->logOrderAction(
                    'order_refunded', $orderReference, $charge, 'success',
                    'Wallet refunded after all providers failed',
                    [], $refundResult
                );

                Order::create([
                    'user_id'      => $user->id,
                    'service_id'   => $request->service_id,
                    'service_name' => $request->service_name,
                    'link'         => $request->link,
                    'quantity'     => $request->quantity,
                    'charge'       => $charge,
                    'status'       => 'cancelled',
                    'api_response' => json_encode(['error' => 'All providers failed']),
                ]);

                return redirect()->route('orders.index')->with('alert', [
                    'type'    => 'error',
                    'message' => 'Order failed. ₦' . number_format($charge, 2) . ' has been refunded to your wallet.',
                ]);
            }

        } catch (\Exception $e) {

            if ($e->getMessage() === 'Insufficient funds') {
                $this->logOrderAction(
                    'order_failed', $orderReference, $charge, 'failed',
                    'Insufficient wallet balance (lock check)',
                    ['balance' => $user->fresh()->balance, 'required' => $charge],
                    null, 'Insufficient funds'
                );

                return redirect()->back()->with('alert', [
                    'type' => 'error', 'message' => 'Insufficient wallet balance.',
                ]);
            }

            $this->logOrderAction(
                'system_error', $orderReference, $charge, 'failed',
                'System error during order processing',
                ['exception_class' => get_class($e)],
                null, $e->getMessage()
            );

            $refundMessage = '';
            try {
                WalletService::refund($user, $charge, 'System Error', $orderReference);
                $this->logOrderAction(
                    'error_refunded', $orderReference, $charge, 'success',
                    'Wallet refunded after system error', [], ['refunded' => true]
                );
                $refundMessage = ' Your wallet has been refunded.';
            } catch (\Exception $re) {
                \Log::error('Refund failed: ' . $re->getMessage());
                $refundMessage = ' Please contact support for a refund.';
            }

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'An error occurred.' . $refundMessage,
            ]);
        }
    }

    // ─── Order History ────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user     = Auth::user();
        $reseller = \App\Models\Reseller::where('user_id', $user->id)->first();

        if ($reseller && $reseller->status === 'active') {
            $customerIds   = \App\Models\ResellerUser::where('reseller_id', $reseller->id)->pluck('user_id')->toArray();
            $customerIds[] = $user->id;
            $query         = \App\Models\Order::whereIn('user_id', $customerIds)->latest();
        } else {
            $query = $user->orders()->latest();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->paginate(30)->withQueryString();
        $this->autoUpdateOrderStatuses($orders);

        return view('order.index', compact('orders'));
    }

    // ─── Auto-status update ───────────────────────────────────────────────────

    protected function autoUpdateOrderStatuses($orders)
    {
        foreach ($orders as $order) {
            if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
                try {
                    // Pass provider_id so we hit the right provider
                    $status = $this->providerService->getOrderStatus(
                        $order->api_order_id,
                        $order->provider_id
                    );

                    if (isset($status['status'])) {
                        $newStatus = $this->mapApiStatus($status['status']);

                        if ($order->status !== $newStatus) {
                            $oldStatus = $order->status;

                            if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                                $this->processAutoRefund($order, $oldStatus, $newStatus);
                                continue;
                            }

                            $order->update([
                                'status'       => $newStatus,
                                'api_response' => json_encode($status),
                            ]);

                            $this->logOrderAction(
                                'auto_status_update', 'AUTO-' . $order->id, 0, 'success',
                                "Status updated: {$oldStatus} → {$newStatus}",
                                ['order_id' => $order->id, 'old_status' => $oldStatus, 'new_status' => $newStatus],
                                $status
                            );
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
                }
            }
        }
    }

    protected function shouldAutoRefund($oldStatus, $newStatus): bool
    {
        return in_array($oldStatus, ['pending', 'processing']) && $newStatus === 'cancelled';
    }

    protected function processAutoRefund($order, $oldStatus, $newStatus)
    {
        try {
            $refundResult = WalletService::refund(
                $order->user,
                $order->charge,
                "Auto-refund for Order #" . substr($order->id, 0, 8) . " - Cancelled by provider",
                'AUTO-REFUND-' . $order->id
            );

            $order->update(['status' => 'cancelled']);

            $this->logOrderAction(
                'auto_refunded', 'AUTO-REFUND-' . $order->id, $order->charge, 'success',
                "Auto-refund: status changed from {$oldStatus} to cancelled",
                ['order_id' => $order->id, 'refund_amount' => $order->charge],
                $refundResult
            );
        } catch (\Exception $e) {
            \Log::error('Auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
        }
    }

    // ─── Check Status (manual) ────────────────────────────────────────────────

    public function checkStatus($orderId)
    {
        $order = Auth::user()->orders()->find($orderId);

        if (!$order) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Order not found.']);
        }

        if (!$order->api_order_id) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'No API order ID found.']);
        }

        try {
            $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);

            if (isset($status['status'])) {
                $order->update([
                    'status'       => $this->mapApiStatus($status['status']),
                    'api_response' => json_encode($status),
                ]);

                return redirect()->back()->with('alert', [
                    'type'    => 'success',
                    'message' => 'Order status updated: ' . ucfirst($status['status']),
                ]);
            }

            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Failed to fetch status.']);

        } catch (\Exception $e) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Error checking status.']);
        }
    }

    // ─── Refill ───────────────────────────────────────────────────────────────

    public function requestRefill($orderId)
    {
        $order = Auth::user()->orders()->findOrFail($orderId);

        if (!$order->api_order_id) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'No API order ID found.']);
        }

        $this->logOrderAction(
            'refill_request', 'REFILL-' . $order->id, 0, 'success',
            'Refill request initiated',
            ['order_id' => $order->id, 'api_order_id' => $order->api_order_id]
        );

        $refillResponse = $this->providerService->createRefill($order->api_order_id, $order->provider_id);

        if (isset($refillResponse['refill'])) {
            $this->logOrderAction(
                'refill_success', 'REFILL-' . $order->id, 0, 'success',
                'Refill requested successfully',
                ['order_id' => $order->id], $refillResponse
            );

            return redirect()->back()->with('alert', [
                'type'    => 'success',
                'message' => 'Refill requested! Refill ID: ' . $refillResponse['refill'],
            ]);
        }

        $errorMessage = $refillResponse['error'] ?? 'Failed to create refill';

        $this->logOrderAction(
            'refill_failed', 'REFILL-' . $order->id, 0, 'failed',
            'Refill request failed',
            ['order_id' => $order->id], $refillResponse, $errorMessage
        );

        return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Failed to create refill.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    protected function mapApiStatus($apiStatus): string
    {
        return [
            'Pending'     => 'pending',
            'In progress' => 'processing',
            'Processing'  => 'processing',
            'Completed'   => 'completed',
            'Partial'     => 'partial',
            'Cancelled'   => 'cancelled',
            'Canceled'    => 'cancelled',
        ][$apiStatus] ?? strtolower($apiStatus);
    }

    protected function logOrderAction(
        $method, $reference, $amount, $status,
        $description, $requestData = [], $responseData = null, $errorMessage = null
    ) {
        try {
            Logged::create([
                'user_id'       => Auth::id(),
                'reference'     => $reference,
                'type'          => 'order',
                'method'        => $method,
                'amount'        => $amount,
                'status'        => $status,
                'description'   => $description,
                'request_data'  => $requestData,
                'response_data' => $responseData,
                'error_message' => $errorMessage,
                'ip_address'    => request()->ip(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log order action: ' . $e->getMessage());
        }
    }
}