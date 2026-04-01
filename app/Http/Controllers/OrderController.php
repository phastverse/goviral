<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Logged;
use App\Services\OgaviralService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Notifications\OrderPlaced;

class OrderController extends Controller
{
    protected $ogaviralService;

    public function __construct(OgaviralService $ogaviralService)
    {
        $this->ogaviralService = $ogaviralService;
    }

    // Show New Order Form
    public function create()
    {
        $services = $this->ogaviralService->getServices();

        // Map social media names to FontAwesome icons (assuming FA6)
        $platformIcons = [
            'Instagram' => 'fa-brands fa-instagram',
            'TikTok'    => 'fa-brands fa-tiktok',
            'Facebook'  => 'fa-brands fa-facebook',
            'Telegram'  => 'fa-brands fa-telegram',
            'Twitter'   => 'fa-brands fa-twitter', // or fa-twitter
            'YouTube'   => 'fa-brands fa-youtube',
            'Spotify'   => 'fa-brands fa-spotify',
            'Pinterest' => 'fa-brands fa-pinterest',
            'LinkedIn'  => 'fa-brands fa-linkedin',
            'Discord'   => 'fa-brands fa-discord',
            'Snapchat'  => 'fa-brands fa-snapchat',
            'Twitch'    => 'fa-brands fa-twitch',
            'WhatsApp'    => 'fa-brands fa-whatsapp',
            'Website'   => 'fa-solid fa-globe', // Generic for others
        ];

        $groupedServices = [];

        foreach ($services as $service) {
            $serviceName = $service['name'];
            $platform = 'Website'; // Default fallback

            // Detect platform from the service name (Case insensitive)
            // We check for specific keywords to group them
            if (stripos($serviceName, 'Instagram') !== false) $platform = 'Instagram';
            elseif (stripos($serviceName, 'TikTok') !== false) $platform = 'TikTok';
            elseif (stripos($serviceName, 'Facebook') !== false) $platform = 'Facebook';
            elseif (stripos($serviceName, 'Telegram') !== false) $platform = 'Telegram';
            elseif (stripos($serviceName, 'Twitter') !== false) $platform = 'Twitter';
            elseif (stripos($serviceName, 'Youtube') !== false || stripos($serviceName, 'YouTube') !== false) $platform = 'YouTube';
            elseif (stripos($serviceName, 'Spotify') !== false) $platform = 'Spotify';
            elseif (stripos($serviceName, 'Pinterest') !== false) $platform = 'Pinterest';
            elseif (stripos($serviceName, 'Linkedin') !== false) $platform = 'LinkedIn';
            elseif (stripos($serviceName, 'Discord') !== false) $platform = 'Discord';
            elseif (stripos($serviceName, 'Snapchat') !== false) $platform = 'Snapchat';
            elseif (stripos($serviceName, 'Twitch') !== false) $platform = 'Twitch';
            elseif (stripos($serviceName, 'WhatsApp') !== false) $platform = 'WhatsApp';
            
            // Get the icon, default to globe if not found
            $icon = $platformIcons[$platform] ?? $platformIcons['Website'];

            // Initialize the platform group if it doesn't exist
            if (!isset($groupedServices[$platform])) {
                $groupedServices[$platform] = [
                    'icon' => $icon,
                    'services' => []
                ];
            }

            // Add service to the platform group
            $groupedServices[$platform]['services'][] = $service;
        }

        return view('order.new', compact('groupedServices'));
    }

    // Process the Order
    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|integer',
            'service_name' => 'required|string',
            'link' => 'required|url',
            'quantity' => 'required|integer|min:10',
            'charge' => 'required|numeric|min:0',
        ]);
 
        $user = Auth::user();
        $orderReference = 'ORD-' . strtoupper(Str::random(8));

        // --- SERVER-SIDE CHARGE CALCULATION (SECURE) ---        
        // fetch the real rate from Ogaviral for this service_id
        $services = $this->ogaviralService->getServices();
        $serviceRate = null;

        foreach ($services as $service) {
            if ((int)$service['service'] === (int)$request->service_id) {
                $serviceRate = (float)$service['rate'];
                break;
            }
        }

        // If service not found or rate is invalid, reject the order
        if ($serviceRate === null || $serviceRate <= 0) {
            $this->logOrderAction(
                'order_failed',
                $orderReference,
                0,
                'failed',
                'Service not found or invalid rate from API',
                ['service_id' => $request->service_id],
                null,
                'Invalid service'
            );

            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Invalid service selected. Please try again.'
            ]);
        }

        // Calculate the correct charge using PricingService markup
        // rate from API is per 1000, so: (quantity / 1000) * marked_up_rate
        $markedUpRate = \App\Services\PricingService::calculatePrice($serviceRate, $request->service_name);
        $serverCharge = round(($request->quantity / 1000) * $markedUpRate, 2);

        // If frontend charge doesn't match server charge, log it and use server charge
        if ((float)$request->charge !== $serverCharge) {
            $this->logOrderAction(
                'order_initiated',
                $orderReference,
                $charge,
                'failed',
                'Charge mismatch detected',
                [
                    'service_id' => $request->service_id,
                    'service_name' => $request->service_name,
                    'link' => $request->link,
                    'quantity' => $request->quantity,
                    'charge' => $charge,
                    'frontend_charge' => $request->charge,
                ],
                ['status' => 'initiated']
            );
        }

        // Use the server-calculated charge from here on
        $charge = $serverCharge;
        // --- END SECURE CALCULATION ---

        // Log initial order request
        $this->logOrderAction(
            'order_initiated',
            $orderReference,
            $charge,
            'success',
            'Order initiated by user',
            [
                'service_id' => $request->service_id,
                'service_name' => $request->service_name,
                'link' => $request->link,
                'quantity' => $request->quantity,
                'charge' => $charge,
                'frontend_charge' => $request->charge,
            ],
            ['status' => 'initiated']
        );

        // --- DUPLICATE ORDER PREVENTION ---
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
                'order_rejected',
                $orderReference,
                $charge,
                'failed',
                'Duplicate order detected',
                [
                    'duplicate_of' => $recentDuplicate->id,
                    'service_id' => $request->service_id,
                    'link' => $request->link,
                    'quantity' => $request->quantity,
                ],
                null,
                'Duplicate order'
            );

            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'A similar order was recently placed. Please wait a few minutes before placing the same order again.'
            ]);
        }
        // --- END DUPLICATE PREVENTION ---

        
        // 1. Check Balance (Double check)
        if ($user->balance < $charge) {
            // Log insufficient balance
            $this->logOrderAction(
                'order_failed',
                $orderReference,
                $charge,
                'failed',
                'Insufficient wallet balance',
                ['balance' => $user->balance, 'required' => $charge],
                null,
                'Insufficient funds'
            );
            
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'Insufficient wallet balance.'
            ]);
        }

        // 2. Transaction: Deduct Wallet
        try {
            WalletService::withdraw(
                $user, 
                $charge, 
                $orderReference, 
                'Order Debit', 
                "Order for: {$request->service_name}"
            );

            // Log successful wallet deduction
            $this->logOrderAction(
                'wallet_debited',
                $orderReference,
                $charge,
                'success',
                'Wallet debited for order',
                ['previous_balance' => $user->balance + $charge],
                ['new_balance' => $user->balance]
            );

            // 3. Send to Ogaviral API
            $apiResponse = $this->ogaviralService->placeOrder(
                $request->service_id, 
                $request->link, 
                $request->quantity
            );

            // 4. Handle API Response
            if (isset($apiResponse['order']) && is_numeric($apiResponse['order'])) {

            // Calculate profit and markup for this order
            $profit = \App\Services\PricingService::calculateProfit(
                $charge,
                $request->quantity,
                $request->service_name
            );

            $markupPercentage = \App\Services\PricingService::getMarkupPercentage($request->service_name);

            // SUCCESS: Save Order
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
            ]);
                // Log successful order
                $this->logOrderAction(
                    'order_success',
                    $orderReference,
                    $charge,
                    'success',
                    'Order placed successfully',
                    [
                        'order_id' => $order->id,
                        'api_order_id' => $apiResponse['order'],
                        'service_id' => $request->service_id,
                        'quantity' => $request->quantity,
                    ],
                    $apiResponse
                );

                //Send Notification
                try {
                    $user->notify(new OrderPlaced($order));
                } catch (\Exception $e) {
                    \Log::error('Failed to send order notification: ' . $e->getMessage());
                }

                // MARK REFERRAL ORDER
                \App\Services\ReferralService::markOrder($user);

                return redirect()->route('orders.index')->with('alert', [
                    'type' => 'success',
                    'message' => 'Order placed successfully! Order ID: ' . $apiResponse['order']
                ]);

            } else {
                // FAILURE: Auto Refund
                $errorMessage = $apiResponse['error'] ?? 'Unknown Error';
                
                // Log API failure
                $this->logOrderAction(
                    'api_failed',
                    $orderReference,
                    $charge,
                    'failed',
                    'API order placement failed',
                    [
                        'service_id' => $request->service_id,
                        'link' => $request->link,
                        'quantity' => $request->quantity,
                    ],
                    $apiResponse,
                    $errorMessage
                );
                
                // Refund the user
                $refundResult = WalletService::refund(
                    $user, 
                    $charge, 
                    'Order Failed',
                    $orderReference
                );

                // Log refund
                $this->logOrderAction(
                    'order_refunded',
                    $orderReference,
                    $charge,
                    'success',
                    'Wallet refunded after API failure',
                    ['original_reference' => $orderReference],
                    $refundResult
                );
                
                // Save failed order for record
                Order::create([
                    'user_id' => $user->id,
                    'service_id' => $request->service_id,
                    'service_name' => $request->service_name,
                    'link' => $request->link,
                    'quantity' => $request->quantity,
                    'charge' => $charge,
                    'status' => 'cancelled',
                    'api_response' => json_encode($apiResponse),
                ]);

                return redirect()->route('orders.index')->with('alert', [
                    'type' => 'error',
                    'message' => 'Order failed ₦' . number_format($charge, 2) . ' has been refunded to your wallet.'
                ]);
            }

        } catch (\Exception $e) {

            // --- INSUFFICIENT FUNDS (thrown by WalletService lock check) ---
            if ($e->getMessage() === 'Insufficient funds') {
                $this->logOrderAction(
                    'order_failed',
                    $orderReference,
                    $charge,
                    'failed',
                    'Insufficient wallet balance',
                    ['balance' => $user->fresh()->balance, 'required' => $charge],
                    null,
                    'Insufficient funds'
                );

                return redirect()->back()->with('alert', [
                    'type' => 'error',
                    'message' => 'Insufficient wallet balance.'
                ]);
            }
            // --- END INSUFFICIENT FUNDS ---

            // Log exception
            $this->logOrderAction(
                'system_error',
                $orderReference,
                $charge,
                'failed',
                'System error during order processing',
                [
                    'service_id' => $request->service_id,
                    'exception_class' => get_class($e),
                ],
                null,
                $e->getMessage()
            );

            // If wallet was deducted but something went wrong, try to refund
            if (isset($orderReference)) {
                try {
                    WalletService::refund(
                        $user, 
                        $charge, 
                        'System Error',
                        $orderReference
                    );

                    // Log refund after system error
                    $this->logOrderAction(
                        'error_refunded',
                        $orderReference,
                        $charge,
                        'success',
                        'Wallet refunded after system error',
                        ['original_reference' => $orderReference],
                        ['refunded' => true]
                    );
                    
                    $refundMessage = ' Your wallet has been refunded.';
                } catch (\Exception $refundException) {
                    \Log::error('Refund failed: ' . $refundException->getMessage());
                    
                    // Log refund failure
                    $this->logOrderAction(
                        'refund_failed',
                        $orderReference,
                        $charge,
                        'failed',
                        'Refund failed after system error',
                        ['original_reference' => $orderReference],
                        null,
                        $refundException->getMessage()
                    );
                    
                    $refundMessage = ' Please contact support for refund.';
                }
            } else {
                $refundMessage = '';
            }

            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'An error occurred: ' . $refundMessage
            ]);
        }
    }

    /// Order History - WITH AUTO STATUS UPDATE
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Check if user is a reseller owner
        $reseller = \App\Models\Reseller::where('user_id', $user->id)->first();
        
        if ($reseller && $reseller->status === 'active') {
            // Get all user IDs under this reseller (customers)
            $customerIds = \App\Models\ResellerUser::where('reseller_id', $reseller->id)
                ->pluck('user_id')
                ->toArray();
            
            // Add the reseller owner's own ID
            $customerIds[] = $user->id;
            
            // Show orders from all customers under this reseller + owner's own orders
            $query = \App\Models\Order::whereIn('user_id', $customerIds)->latest();
        } else {
            // Regular user - show only their orders
            $query = $user->orders()->latest();
        }
        
        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        $orders = $query->paginate(30)->withQueryString();
        
        // Auto-update status for pending/processing orders
        $this->autoUpdateOrderStatuses($orders);
        
        return view('order.index', compact('orders'));
    }
    /**
     * Automatically check and update statuses for pending/processing orders
     * WITH AUTO-REFUND SUPPORT
     */
    protected function autoUpdateOrderStatuses($orders)
    {
        foreach ($orders as $order) {
            // Only check orders that are not completed or cancelled
            if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
                try {
                    // Get status from API
                    $status = $this->ogaviralService->getOrderStatus($order->api_order_id);
                    
                    if (isset($status['status'])) {
                        // Map API status to our database status
                        $apiStatus = $status['status'];
                        $newStatus = $this->mapApiStatus($apiStatus);
                        
                        // Only update if status has changed
                        if ($order->status !== $newStatus) {
                            $oldStatus = $order->status;
                            
                            // Check if order should be auto-refunded
                            if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                                $this->processAutoRefund($order, $oldStatus, $newStatus);
                                continue;
                            }
                            
                            // Update order status
                            $order->update([
                                'status' => $newStatus,
                                'api_response' => json_encode($status),
                            ]);
                            
                            // Log the auto-update
                            $this->logOrderAction(
                                'auto_status_update',
                                'AUTO-' . $order->id,
                                0,
                                'success',
                                'Order status auto-updated from ' . $oldStatus . ' to ' . $newStatus,
                                [
                                    'order_id' => $order->id,
                                    'api_order_id' => $order->api_order_id,
                                    'old_status' => $oldStatus,
                                    'new_status' => $newStatus,
                                ],
                                $status
                            );
                        }
                    }
                } catch (\Exception $e) {
                    // Log error but don't stop the page from loading
                    \Log::error('Auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
                    
                    $this->logOrderAction(
                        'auto_status_update_failed',
                        'AUTO-' . $order->id,
                        0,
                        'failed',
                        'Auto status update failed',
                        [
                            'order_id' => $order->id,
                            'api_order_id' => $order->api_order_id,
                        ],
                        null,
                        $e->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Check if order should be auto-refunded
     */
    protected function shouldAutoRefund($oldStatus, $newStatus)
    {
        // If old status was pending or processing and new status is cancelled
        return in_array($oldStatus, ['pending', 'processing']) && $newStatus === 'cancelled';
    }

    /**
     * Process automatic refund
     */
    protected function processAutoRefund($order, $oldStatus, $newStatus)
    {
        try {
            // Refund the user using WalletService
            $refundResult = WalletService::refund(
                $order->user, 
                $order->charge, 
                "Auto-refund for Order #" . substr($order->id, 0, 8) . " - Order cancelled by provider",
                'AUTO-REFUND-' . $order->id
            );

            // Update order status to cancelled
            $order->update(['status' => 'cancelled']);

            // Log the auto-refund
            $this->logOrderAction(
                'auto_refunded',
                'AUTO-REFUND-' . $order->id,
                $order->charge,
                'success',
                'Auto-refund processed - Order cancelled by provider (Status changed from ' . $oldStatus . ' to cancelled)',
                [
                    'order_id' => $order->id,
                    'api_order_id' => $order->api_order_id,
                    'refund_amount' => $order->charge,
                    'old_status' => $oldStatus,
                    'new_status' => 'cancelled',
                    'refund_type' => 'automatic'
                ],
                $refundResult
            );

            // \Log::info('Auto-refund processed for order ' . $order->id . ' - Amount: ₦' . number_format($order->charge, 2));

        } catch (\Exception $e) {
            \Log::error('Auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
            
            // Log the auto-refund failure
            $this->logOrderAction(
                'auto_refund_failed',
                'AUTO-REFUND-' . $order->id,
                $order->charge,
                'failed',
                'Auto-refund failed',
                [
                    'order_id' => $order->id,
                    'api_order_id' => $order->api_order_id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
                null,
                $e->getMessage()
            );
        }
    }

public function checkStatus($orderId)
{
    $order = Auth::user()->orders()->find($orderId);
    
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
            $order->update([
                'status' => $status['status'],
                'api_response' => json_encode($status),
            ]);

            return redirect()->back()->with('alert', [
                'type' => 'success',
                'message' => 'Order status updated: ' . ucfirst($status['status'])
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
    // Request Refill
    public function requestRefill($orderId)
    {
        $order = Auth::user()->orders()->findOrFail($orderId);

        if (!$order->api_order_id) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'No API order ID found for this order.'
            ]);
        }

        // Log refill request initiation
        $this->logOrderAction(
            'refill_request',
            'REFILL-' . $order->id,
            0,
            'success',
            'Refill request initiated',
            ['order_id' => $order->id, 'api_order_id' => $order->api_order_id],
            null
        );

        $refillResponse = $this->ogaviralService->createRefill($order->api_order_id);

        if (isset($refillResponse['refill'])) {
            // Log successful refill
            $this->logOrderAction(
                'refill_success',
                'REFILL-' . $order->id,
                0,
                'success',
                'Refill requested successfully',
                ['order_id' => $order->id, 'api_order_id' => $order->api_order_id],
                $refillResponse
            );

            return redirect()->back()->with('alert', [
                'type' => 'success',
                'message' => 'Refill requested successfully! Refill ID: ' . $refillResponse['refill']
            ]);
        }

        $errorMessage = $refillResponse['error'] ?? 'Failed to create refill';

        // Log failed refill
        $this->logOrderAction(
            'refill_failed',
            'REFILL-' . $order->id,
            0,
            'failed',
            'Refill request failed',
            ['order_id' => $order->id, 'api_order_id' => $order->api_order_id],
            $refillResponse,
            $errorMessage
        );

        return redirect()->back()->with('alert', [
            'type' => 'error',
            'message' => 'Failed to create refill'
        ]);
    }

    /**
     * Map Ogaviral API status to our database status
     */
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

    /**
     * Log order-related actions to the Logged model
     */
    protected function logOrderAction(
        $method, 
        $reference, 
        $amount, 
        $status, 
        $description, 
        $requestData = [], 
        $responseData = null, 
        $errorMessage = null
    ) {
        try {
            Logged::create([
                'user_id' => Auth::id(),
                'reference' => $reference,
                'type' => 'order',
                'method' => $method,
                'amount' => $amount,
                'status' => $status,
                'description' => $description,
                'request_data' => $requestData,
                'response_data' => $responseData,
                'error_message' => $errorMessage,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log order action: ' . $e->getMessage());
        }
    }
}