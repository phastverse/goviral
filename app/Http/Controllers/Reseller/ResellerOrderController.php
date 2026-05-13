<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Logged;
use App\Services\ProviderService;
use App\Services\WalletService;
use App\Services\ResellerPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Notifications\OrderPlaced;

class ResellerOrderController extends Controller
{
    protected ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    public function create()
    {
        $reseller = $this->currentReseller();
        $services = $this->providerService->getServices();

        $hiddenServiceIds = $reseller->serviceMarkups()
            ->where('is_hidden', true)
            ->pluck('service_id')
            ->flip()
            ->all();

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
            if (isset($hiddenServiceIds[$service['service']])) continue;

            $serviceName = $service['name'];
            $platform    = 'Website';

            if      (stripos($serviceName, 'Instagram') !== false) $platform = 'Instagram';
            elseif  (stripos($serviceName, 'TikTok')    !== false) $platform = 'TikTok';
            elseif  (stripos($serviceName, 'Facebook')  !== false) $platform = 'Facebook';
            elseif  (stripos($serviceName, 'Telegram')  !== false) $platform = 'Telegram';
            elseif  (stripos($serviceName, 'Twitter')   !== false) $platform = 'Twitter';
            elseif  (stripos($serviceName, 'Youtube')   !== false
                  || stripos($serviceName, 'YouTube')   !== false) $platform = 'YouTube';
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

        return view('reseller.order.new', compact('groupedServices', 'reseller'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'service_id'   => 'required|integer',
            'service_name' => 'required|string',
            'link'         => 'required|url',
            'quantity'     => 'required|integer|min:10',
            'charge'       => 'required|numeric|min:0',
        ]);

        $reseller       = $this->currentReseller();
        $user           = Auth::user();
        $orderReference = 'RORD-' . strtoupper(Str::random(8));

        // Server-side charge calculation
        $services    = $this->providerService->getServices();
        $serviceRate = null;

        foreach ($services as $service) {
            if ((int) $service['service'] === (int) $request->service_id) {
                $serviceRate = (float) $service['rate'];
                break;
            }
        }

        if ($serviceRate === null || $serviceRate <= 0) {
            return redirect()->back()->with('alert', [
                'type' => 'error', 'message' => 'Invalid service selected. Please try again.',
            ]);
        }

        $customerCharge = ResellerPricingService::calculateCharge(
            $serviceRate, (int) $request->service_id, (int) $request->quantity, $reseller
        );
        $platformCost = ResellerPricingService::calculateYourCost(
            $serviceRate, $request->service_name, (int) $request->quantity
        );
        $resellerProfit = round($customerCharge - $platformCost, 2);

        if (abs((float) $request->charge - $customerCharge) > 1.00) {
            \Log::warning('Reseller charge mismatch', [
                'frontend' => $request->charge, 'server' => $customerCharge, 'user_id' => $user->id,
            ]);
        }

        // Duplicate prevention
        $duplicate = Order::where('user_id', $user->id)
            ->where('service_id', $request->service_id)
            ->where('link', $request->link)
            ->where('quantity', $request->quantity)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->where('created_at', '>=', now()->subMinutes(3))
            ->lockForUpdate()->first();

        if ($duplicate) {
            return redirect()->back()->with('alert', [
                'type' => 'error', 'message' => 'A similar order was recently placed. Please wait a few minutes.',
            ]);
        }

        if ($user->balance < $customerCharge) {
            return redirect()->back()->with('alert', [
                'type' => 'error', 'message' => 'Insufficient wallet balance.',
            ]);
        }

        $resellerOwner = $reseller->owner;
        if ($resellerOwner->balance < $platformCost) {
            return redirect()->back()->with('alert', [
                'type' => 'error', 'message' => 'Service temporarily unavailable. Please contact support.',
            ]);
        }

        try {
            $placedOrderId = null;

            DB::transaction(function () use (
                $user, $resellerOwner, $reseller,
                $customerCharge, $platformCost, $resellerProfit,
                $orderReference, $request, &$placedOrderId
            ) {
                // Debit customer
                WalletService::withdraw($user, $customerCharge, $orderReference, 'Panel Order(User)',
                    "Order for: {$request->service_name} on {$reseller->panel_name}");

                // Settle reseller wallet
                WalletService::deposit($resellerOwner, $customerCharge, 'RPAY-' . $orderReference,
                    'Reseller Customer Payment', "Customer order — {$reseller->panel_name}");
                WalletService::withdraw($resellerOwner, $platformCost, 'RCOST-' . $orderReference,
                    'Booster Cost For Customer Order (Panel)', "API cost for {$orderReference}");

                // Place via ProviderService (auto-fallback)
                $result = $this->providerService->placeOrder(
                    $request->service_id, $request->link, $request->quantity
                );

                if ($result !== null) {
                    $apiResponse    = $result['response'];
                    $chosenProvider = $result['provider'];

                    $order = Order::create([
                        'user_id'           => $user->id,
                        'reseller_id'       => $reseller->id,
                        'service_id'        => $request->service_id,
                        'service_name'      => $request->service_name,
                        'link'              => $request->link,
                        'quantity'          => $request->quantity,
                        'charge'            => $customerCharge,
                        'profit'            => $resellerProfit,
                        'markup_percentage' => $reseller->default_markup_percent,
                        'status'            => 'processing',
                        'api_order_id'      => $apiResponse['order'],
                        'api_response'      => json_encode($apiResponse),
                        'provider_id'       => $chosenProvider->id,   // ← track provider
                    ]);

                    try { $user->notify(new OrderPlaced($order)); } catch (\Exception $e) {}

                    $placedOrderId = $apiResponse['order'];

                } else {
                    // All providers failed — full unwind
                    WalletService::refund($user, $customerCharge, 'Order Failed', $orderReference);
                    WalletService::refund($resellerOwner, $platformCost, 'API Failed Refund', $orderReference);
                    WalletService::withdraw($resellerOwner, $customerCharge, 'RUNWIND-' . $orderReference,
                        'Customer Payment Reversal', "Reversal — API failure {$orderReference}");

                    Order::create([
                        'user_id'      => $user->id,
                        'reseller_id'  => $reseller->id,
                        'service_id'   => $request->service_id,
                        'service_name' => $request->service_name,
                        'link'         => $request->link,
                        'quantity'     => $request->quantity,
                        'charge'       => $customerCharge,
                        'status'       => 'cancelled',
                        'api_response' => json_encode(['error' => 'All providers failed']),
                    ]);

                    throw new \Exception('api_failed:All providers failed');
                }
            });

            return redirect('/orders')->with('alert', [
                'type' => 'success', 'message' => 'Order placed successfully!',
            ]);

        } catch (\Exception $e) {
            if (str_starts_with($e->getMessage(), 'api_failed:')) {
                return redirect()->route('reseller.orders.index')->with('alert', [
                    'type' => 'error', 'message' => 'Order failed. Your wallet has been refunded.',
                ]);
            }
            if ($e->getMessage() === 'Insufficient funds') {
                return redirect()->back()->with('alert', [
                    'type' => 'error', 'message' => 'Insufficient wallet balance.',
                ]);
            }
            \Log::error('Reseller order exception: ' . $e->getMessage());
            return redirect()->back()->with('alert', [
                'type' => 'error', 'message' => 'An error occurred. Please contact support.',
            ]);
        }
    }

    public function index(Request $request)
    {
        $reseller = $this->currentReseller();
        $user     = Auth::user();

        $query = $user->id === $reseller->user_id
            ? Order::where('reseller_id', $reseller->id)->latest()
            : $user->orders()->where('reseller_id', $reseller->id)->latest();

        if ($request->filled('status')) $query->where('status', $request->status);

        $orders = $query->paginate(30)->withQueryString();
        $this->autoUpdateOrderStatuses($orders);

        return view('reseller.order.index', compact('orders', 'reseller'));
    }

    protected function autoUpdateOrderStatuses($orders)
    {
        foreach ($orders as $order) {
            if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
                try {
                    $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);
                    if (isset($status['status'])) {
                        $newStatus = $this->mapApiStatus($status['status']);
                        if ($order->status !== $newStatus) {
                            $oldStatus = $order->status;
                            if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                                $this->processAutoRefund($order, $oldStatus, $newStatus);
                                continue;
                            }
                            $order->update(['status' => $newStatus, 'api_response' => json_encode($status)]);
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Reseller auto-update failed for order ' . $order->id . ': ' . $e->getMessage());
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
            $reseller      = $this->currentReseller();
            $resellerOwner = $reseller->owner;

            WalletService::refund($order->user, $order->charge,
                "Auto-refund — provider cancelled order", 'AUTO-REFUND-' . $order->id);

            if ($order->reseller_id) {
                WalletService::refund($resellerOwner, $order->charge - $order->profit,
                    "Auto-refund platform cost", 'AUTO-PLATFORM-REFUND-' . $order->id);
            }

            $order->update(['status' => 'cancelled']);

        } catch (\Exception $e) {
            \Log::error('Reseller auto-refund failed: ' . $e->getMessage());
        }
    }

    public function checkStatus($id)
    {
        $user     = Auth::user();
        $reseller = $this->currentReseller();

        $order = $user->id === $reseller->user_id
            ? Order::where('id', $id)->where('reseller_id', $reseller->id)->first()
            : $user->orders()->where('reseller_id', $reseller->id)->find($id);

        if (!$order) return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Order not found.']);
        if (!$order->api_order_id) return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'No API order ID.']);

        try {
            $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);
            if (isset($status['status'])) {
                $newStatus = $this->mapApiStatus($status['status']);
                $oldStatus = $order->status;
                if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                    $this->processAutoRefund($order, $oldStatus, $newStatus);
                    return redirect()->back()->with('alert', [
                        'type' => 'success', 'message' => 'Order cancelled by provider and refunded.',
                    ]);
                }
                $order->update(['status' => $newStatus, 'api_response' => json_encode($status)]);
                return redirect()->back()->with('alert', [
                    'type' => 'success', 'message' => 'Status updated: ' . ucfirst($newStatus),
                ]);
            }
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Failed to fetch status.']);
        } catch (\Exception $e) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Error checking status.']);
        }
    }

    public function requestRefill($id)
    {
        $user     = Auth::user();
        $reseller = $this->currentReseller();

        $order = $user->id === $reseller->user_id
            ? Order::where('id', $id)->where('reseller_id', $reseller->id)->first()
            : $user->orders()->where('reseller_id', $reseller->id)->find($id);

        if (!$order)             return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Order not found.']);
        if (!$order->api_order_id) return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'No API order ID.']);

        try {
            $response = $this->providerService->createRefill($order->api_order_id, $order->provider_id);
            if (isset($response['refill'])) {
                return redirect()->back()->with('alert', [
                    'type' => 'success', 'message' => 'Refill requested! ID: ' . $response['refill'],
                ]);
            }
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Failed to create refill.']);
        } catch (\Exception $e) {
            return redirect()->back()->with('alert', ['type' => 'error', 'message' => 'Error requesting refill.']);
        }
    }

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
}