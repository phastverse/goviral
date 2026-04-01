<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Services\OgaviralService;
use App\Services\WalletService;
use App\Models\Logged;
use App\Traits\ChecksPendingDeposits;

class DashboardController extends Controller
{
    use ChecksPendingDeposits;
    protected $ogaviralService;


    public function __construct(OgaviralService $ogaviralService)
    {
        $this->ogaviralService = $ogaviralService;
    }

public function index()
{
    $user = auth()->user();
    
    // CHECK PENDING DEPOSITS (batch of 5 for this user only)
    $this->checkUserPendingDeposits($user, 5);
    
    // Check if user is a reseller owner
    $reseller = \App\Models\Reseller::where('user_id', $user->id)
        ->where('status', 'active')
        ->first();
    
    $isResellerOwner = ($reseller !== null);
    
    if ($isResellerOwner) {
        // Get all user IDs under this reseller (customers)
        $customerIds = \App\Models\ResellerUser::where('reseller_id', $reseller->id)
            ->pluck('user_id')
            ->toArray();
        
        // Add the reseller owner's own ID
        $customerIds[] = $user->id;
        
        // Recent Orders - from all customers under this reseller
        $recentOrders = \App\Models\Order::whereIn('user_id', $customerIds)
            ->latest()
            ->limit(5)
            ->get();
        
        // Auto-update recent orders status
        $this->autoUpdateOrderStatuses($recentOrders);
        
        // 1. Wallet Balance (owner's personal balance)
        $balance = $user->balance;
        
        // 2. Order Statistics (from all customers under this reseller)
        $totalOrders = \App\Models\Order::whereIn('user_id', $customerIds)->count();
        $completedOrders = \App\Models\Order::whereIn('user_id', $customerIds)->where('status', 'completed')->count();
        $processingOrders = \App\Models\Order::whereIn('user_id', $customerIds)->where('status', 'processing')->count();
        $pendingOrders = \App\Models\Order::whereIn('user_id', $customerIds)->where('status', 'pending')->count();
        $totalSpent = \App\Models\Order::whereIn('user_id', $customerIds)->sum('charge');
        
    } else {
        // Regular user - only their own orders
        $recentOrders = $user->orders()->latest()->limit(5)->get();
        
        // Auto-update recent orders status
        $this->autoUpdateOrderStatuses($recentOrders);
        
        // 1. Wallet Balance
        $balance = $user->balance;
        
        // 2. Order Statistics (Fresh after update)
        $totalOrders = $user->orders()->count();
        $completedOrders = $user->orders()->where('status', 'completed')->count();
        $processingOrders = $user->orders()->where('status', 'processing')->count();
        $pendingOrders = $user->orders()->where('status', 'pending')->count();
        $totalSpent = $user->orders()->sum('charge');
    }
    
    return view('dashboard', compact(
        'balance', 
        'totalOrders', 
        'completedOrders', 
        'processingOrders',
        'pendingOrders',
        'totalSpent', 
        'recentOrders',
        'isResellerOwner'
    ));
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
                                'dashboard_auto_update',
                                'DASH-' . $order->id,
                                0,
                                'success',
                                'Order status auto-updated on dashboard from ' . $oldStatus . ' to ' . $newStatus,
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
                    \Log::error('Dashboard auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
                    
                    $this->logOrderAction(
                        'dashboard_auto_update_failed',
                        'DASH-' . $order->id,
                        0,
                        'failed',
                        'Dashboard auto status update failed',
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
                'dashboard_auto_refunded',
                'AUTO-REFUND-' . $order->id,
                $order->charge,
                'success',
                'Auto-refund processed on dashboard - Order cancelled by provider (Status changed from ' . $oldStatus . ' to cancelled)',
                [
                    'order_id' => $order->id,
                    'api_order_id' => $order->api_order_id,
                    'refund_amount' => $order->charge,
                    'old_status' => $oldStatus,
                    'new_status' => 'cancelled',
                    'refund_type' => 'automatic',
                    'triggered_from' => 'dashboard'
                ],
                $refundResult
            );

            \Log::info('Dashboard auto-refund processed for order ' . $order->id . ' - Amount: ₦' . number_format($order->charge, 2));

        } catch (\Exception $e) {
            \Log::error('Dashboard auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
            
            // Log the auto-refund failure
            $this->logOrderAction(
                'dashboard_auto_refund_failed',
                'AUTO-REFUND-' . $order->id,
                $order->charge,
                'failed',
                'Dashboard auto-refund failed',
                [
                    'order_id' => $order->id,
                    'api_order_id' => $order->api_order_id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'triggered_from' => 'dashboard'
                ],
                null,
                $e->getMessage()
            );
        }
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
                'user_id' => auth()->id(),
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