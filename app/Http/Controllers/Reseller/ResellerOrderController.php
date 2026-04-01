<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Logged;
use App\Services\OgaviralService;
use App\Services\WalletService;
use App\Services\ResellerPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Notifications\OrderPlaced;

class ResellerOrderController extends Controller
{
    protected OgaviralService $ogaviralService;

    public function __construct(OgaviralService $ogaviralService)
    {
        $this->ogaviralService = $ogaviralService;
    }

    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    public function create()
    {
        $reseller  = $this->currentReseller();
        $services  = $this->ogaviralService->getServices();

        // Build the hidden-service lookup
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
            // Skip hidden services
            if (isset($hiddenServiceIds[$service['service']])) {
                continue;
            }

            $serviceName = $service['name'];
            $platform    = 'Website';

            if (stripos($serviceName, 'Instagram') !== false)       $platform = 'Instagram';
            elseif (stripos($serviceName, 'TikTok') !== false)      $platform = 'TikTok';
            elseif (stripos($serviceName, 'Facebook') !== false)    $platform = 'Facebook';
            elseif (stripos($serviceName, 'Telegram') !== false)    $platform = 'Telegram';
            elseif (stripos($serviceName, 'Twitter') !== false)     $platform = 'Twitter';
            elseif (stripos($serviceName, 'Youtube') !== false
                 || stripos($serviceName, 'YouTube') !== false)     $platform = 'YouTube';
            elseif (stripos($serviceName, 'Spotify') !== false)     $platform = 'Spotify';
            elseif (stripos($serviceName, 'Pinterest') !== false)   $platform = 'Pinterest';
            elseif (stripos($serviceName, 'Linkedin') !== false)    $platform = 'LinkedIn';
            elseif (stripos($serviceName, 'Discord') !== false)     $platform = 'Discord';
            elseif (stripos($serviceName, 'Snapchat') !== false)    $platform = 'Snapchat';
            elseif (stripos($serviceName, 'Twitch') !== false)      $platform = 'Twitch';
            elseif (stripos($serviceName, 'WhatsApp') !== false)    $platform = 'WhatsApp';

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

        $reseller = $this->currentReseller();
        $user     = Auth::user();

        // Only the reseller's own wallet (the owner user) gets debited for the API call.
        // End-customers pay INTO the reseller's wallet; the reseller's platform wallet
        // pays Ogaviral. So the flow is:
        //   customer wallet → reseller owner wallet → Ogaviral
        // We handle both debits atomically inside a DB transaction.

        $orderReference = 'RORD-' . strtoupper(Str::random(8));

        // --- SERVER-SIDE CHARGE CALCULATION ---
        $services    = $this->ogaviralService->getServices();
        $serviceRate = null;

        foreach ($services as $service) {
            if ((int) $service['service'] === (int) $request->service_id) {
                $serviceRate = (float) $service['rate'];
                break;
            }
        }

        if ($serviceRate === null || $serviceRate <= 0) {
            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Invalid service selected. Please try again.',
            ]);
        }

        // What the customer pays (reseller markup on top of your platform markup)
        $customerCharge = ResellerPricingService::calculateCharge(
            $serviceRate,
            (int) $request->service_id,
            (int) $request->quantity,
            $reseller
        );

        // What the reseller's platform wallet gets debited (your cost)
        $platformCost = ResellerPricingService::calculateYourCost(
            $serviceRate,
            $request->service_name,
            (int) $request->quantity
        );

        // Reseller profit = what customer paid − what goes to Ogaviral via your platform
        $resellerProfit = round($customerCharge - $platformCost, 2);

        // Guard: frontend charge must roughly match (allow ±1 NGN rounding tolerance)
        if (abs((float) $request->charge - $customerCharge) > 1.00) {
            // Log the mismatch silently and use server charge
            \Log::warning('Reseller order charge mismatch', [
                'frontend' => $request->charge,
                'server'   => $customerCharge,
                'user_id'  => $user->id,
                'reseller' => $reseller->id,
            ]);
        }

        // --- DUPLICATE PREVENTION ---
        $duplicate = Order::where('user_id', $user->id)
            ->where('service_id', $request->service_id)
            ->where('link', $request->link)
            ->where('quantity', $request->quantity)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->where('created_at', '>=', now()->subMinutes(3))
            ->lockForUpdate()
            ->first();

        if ($duplicate) {
            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'A similar order was recently placed. Please wait a few minutes.',
            ]);
        }

        // --- BALANCE CHECK ---
        if ($user->balance < $customerCharge) {
            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Insufficient wallet balance.',
            ]);
        }

        // Also check the reseller owner's platform wallet can cover the API cost
        $resellerOwner = $reseller->owner;
        if ($resellerOwner->balance < $platformCost) {
            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Service temporarily unavailable. Please contact support.',
            ]);
        }

        try {
            DB::transaction(function () use (
                $user, $resellerOwner, $reseller,
                $customerCharge, $platformCost, $resellerProfit,
                $orderReference, $request, $serviceRate
            ) {
                // 1. Debit the end-customer's wallet
                WalletService::withdraw(
                    $user,
                    $customerCharge,
                    $orderReference,
                    'Panel Order(User)',
                    "Order for: {$request->service_name} on {$reseller->panel_name}"
                );

                // 2. Credit the reseller's platform wallet with what the customer paid,
                //    then immediately debit it the platform cost.
                //    Net effect: reseller keeps the profit, your platform is whole.
                WalletService::deposit(
                    $resellerOwner,
                    $customerCharge,
                    'RPAY-' . $orderReference,
                    'Reseller Customer Payment',
                    "Customer order payment — {$reseller->panel_name}"
                );

                WalletService::withdraw(
                    $resellerOwner,
                    $platformCost,
                    'RCOST-' . $orderReference,
                    'Booster Cost For Customer Order (Panel)',
                    "API cost for order {$orderReference}"
                );

                // 3. Place the order with Ogaviral
                $apiResponse = $this->ogaviralService->placeOrder(
                    $request->service_id,
                    $request->link,
                    $request->quantity
                );

                if (isset($apiResponse['order']) && is_numeric($apiResponse['order'])) {
                    // 4. Save the order — tagged to both user and reseller
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
                    ]);

                    try {
                        $user->notify(new OrderPlaced($order));
                    } catch (\Exception $e) {
                        \Log::error('Reseller order notification failed: ' . $e->getMessage());
                    }

                    // Store for redirect message
                    $this->successOrderId = $apiResponse['order'];
                } else {
                    // API failed — refund the customer and unwind reseller wallets
                    $errorMessage = $apiResponse['error'] ?? 'Unknown Error';

                    WalletService::refund($user, $customerCharge, 'Order Failed', $orderReference);
                    WalletService::refund($resellerOwner, $platformCost, 'API Failed Refund', $orderReference);
                    WalletService::withdraw(
                        $resellerOwner,
                        $customerCharge,
                        'RUNWIND-' . $orderReference,
                        'Customer Payment Reversal',
                        "Reversal — API failure {$orderReference}"
                    );

                    Order::create([
                        'user_id'      => $user->id,
                        'reseller_id'  => $reseller->id,
                        'service_id'   => $request->service_id,
                        'service_name' => $request->service_name,
                        'link'         => $request->link,
                        'quantity'     => $request->quantity,
                        'charge'       => $customerCharge,
                        'status'       => 'cancelled',
                        'api_response' => json_encode($apiResponse),
                    ]);

                    throw new \Exception('api_failed:' . $errorMessage);
                }
            });

            return redirect('/orders')->with('alert', [
                'type'    => 'success',
                'message' => 'Order placed successfully!',
            ]);

        } catch (\Exception $e) {
            if (str_starts_with($e->getMessage(), 'api_failed:')) {
                $err = str_replace('api_failed:', '', $e->getMessage());
                return redirect()->route('reseller.orders.index')->with('alert', [
                    'type'    => 'error',
                    'message' => 'Order failed. Your wallet has been refunded.',
                ]);
            }

            if ($e->getMessage() === 'Insufficient funds') {
                return redirect()->back()->with('alert', [
                    'type'    => 'error',
                    'message' => 'Insufficient wallet balance.',
                ]);
            }

            \Log::error('Reseller order exception: ' . $e->getMessage());

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'An error occurred. Please contact support.',
            ]);
        }
    }

       public function index(Request $request)
    {
        $reseller = $this->currentReseller();
        $user = Auth::user();
        
        if ($user->id === $reseller->user_id) {
            $query = Order::where('reseller_id', $reseller->id)->latest();
        } else {
            $query = $user->orders()->where('reseller_id', $reseller->id)->latest();
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        $orders = $query->paginate(30)->withQueryString();
        
        $this->autoUpdateOrderStatuses($orders);
        
        return view('reseller.order.index', compact('orders', 'reseller'));
    }

    /**
     * Auto-update order statuses for pending/processing orders
     */
    protected function autoUpdateOrderStatuses($orders)
    {
        foreach ($orders as $order) {
            if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
                try {
                    $status = $this->ogaviralService->getOrderStatus($order->api_order_id);
                    
                    if (isset($status['status'])) {
                        // Map API status to our database status
                        $newStatus = $this->mapApiStatus($status['status']);
                        
                        if ($order->status !== $newStatus) {
                            $oldStatus = $order->status;
                            
                            if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                                $this->processAutoRefund($order, $oldStatus, $newStatus);
                                continue;
                            }
                            
                            $order->update([
                                'status' => $newStatus,
                                'api_response' => json_encode($status),
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Reseller auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if order should be auto-refunded
     */
    protected function shouldAutoRefund($oldStatus, $newStatus)
    {
        return in_array($oldStatus, ['pending', 'processing']) && $newStatus === 'cancelled';
    }

    /**
     * Process automatic refund
     */
    protected function processAutoRefund($order, $oldStatus, $newStatus)
    {
        try {
            $reseller = $this->currentReseller();
            $resellerOwner = $reseller->owner;
            
            WalletService::refund(
                $order->user, 
                $order->charge, 
                "Auto-refund for Order - Order cancelled by provider",
                'AUTO-REFUND-' . $order->id
            );
            
            if ($order->reseller_id) {
                WalletService::refund(
                    $resellerOwner,
                    $order->charge - $order->profit,
                    "Auto-refund platform cost for cancelled order",
                    'AUTO-PLATFORM-REFUND-' . $order->id
                );
            }

            $order->update(['status' => 'cancelled']);

        } catch (\Exception $e) {
            \Log::error('Reseller auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
        }
    }

    public function checkStatus($id)
    {
        $user = Auth::user();
        $reseller = $this->currentReseller();
        
        if ($user->id === $reseller->user_id) {
            $order = Order::where('id', $id)->where('reseller_id', $reseller->id)->first();
        } else {
            $order = $user->orders()->where('reseller_id', $reseller->id)->find($id);
        }
        
        if (!$order) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Order not found.'
            ]);
        }

        if (!$order->api_order_id) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'No API order ID found for this order.'
            ]);
        }

        try {
            $status = $this->ogaviralService->getOrderStatus($order->api_order_id);

            if (isset($status['status'])) {
                $newStatus = $this->mapApiStatus($status['status']);
                $oldStatus = $order->status;
                
                if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                    $this->processAutoRefund($order, $oldStatus, $newStatus);
                    return redirect()->back()->with('alert', [
                        'type' => 'success',
                        'message' => 'Order cancelled by provider. ₦' . number_format($order->charge, 2) . ' has been refunded.'
                    ]);
                }
                
                $order->update([
                    'status' => $newStatus,
                    'api_response' => json_encode($status),
                ]);

                return redirect()->back()->with('alert', [
                    'type' => 'success',
                    'message' => 'Order status updated: ' . ucfirst($newStatus)
                ]);
            }

            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Failed to fetch order status.'
            ]);
            
        } catch (\Exception $e) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Error checking order status.'
            ]);
        }
    }

    public function requestRefill($id)
    {
        $user = Auth::user();
        $reseller = $this->currentReseller();
        
        if ($user->id === $reseller->user_id) {
            $order = Order::where('id', $id)->where('reseller_id', $reseller->id)->first();
        } else {
            $order = $user->orders()->where('reseller_id', $reseller->id)->find($id);
        }

        if (!$order) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Order not found.'
            ]);
        }

        if (!$order->api_order_id) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'No API order ID found for this order.'
            ]);
        }

        try {
            $refillResponse = $this->ogaviralService->createRefill($order->api_order_id);

            if (isset($refillResponse['refill'])) {
                return redirect()->back()->with('alert', [
                    'type' => 'success',
                    'message' => 'Refill requested successfully! Refill ID: ' . $refillResponse['refill']
                ]);
            }

            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Failed to create refill'
            ]);
            
        } catch (\Exception $e) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Error requesting refill.'
            ]);
        }
    }

    protected function mapApiStatus($apiStatus)
    {
        $statusMap = [
            'Pending' => 'pending',
            'In progress' => 'processing',
            'Processing' => 'processing',
            'Completed' => 'completed',
            'Partial' => 'partial',
            'Cancelled' => 'cancelled',
            'Canceled' => 'cancelled',
        ];
        
        return $statusMap[$apiStatus] ?? strtolower($apiStatus);
    }

}