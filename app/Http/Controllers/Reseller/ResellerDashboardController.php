<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;

class ResellerDashboardController extends Controller
{
    public function index()
    {
        $reseller = app('current_reseller');
        $user     = Auth::user();
        
        $isOwner = ($user->id === $reseller->user_id);
        
        if ($isOwner) {
            $totalOrders = Order::where('reseller_id', $reseller->id)->count();
            $pendingOrders = Order::where('reseller_id', $reseller->id)->where('status', 'pending')->count();
            $processingOrders = Order::where('reseller_id', $reseller->id)->where('status', 'processing')->count();
            $completedOrders = Order::where('reseller_id', $reseller->id)->where('status', 'completed')->count();
            $totalSpent = Order::where('reseller_id', $reseller->id)->sum('charge');
            $recentOrders = Order::where('reseller_id', $reseller->id)->latest()->take(5)->get();
        } else {
            $totalOrders = $user->orders()->where('reseller_id', $reseller->id)->count();
            $pendingOrders = $user->orders()->where('reseller_id', $reseller->id)->where('status', 'pending')->count();
            $processingOrders = $user->orders()->where('reseller_id', $reseller->id)->where('status', 'processing')->count();
            $completedOrders = $user->orders()->where('reseller_id', $reseller->id)->where('status', 'completed')->count();
            $totalSpent = $user->orders()->where('reseller_id', $reseller->id)->sum('charge');
            $recentOrders = $user->orders()->where('reseller_id', $reseller->id)->latest()->take(5)->get();
        }
        
        $balance = $user->balance;
        
        $this->autoUpdateOrderStatuses($recentOrders);

        return view('reseller.dashboard', compact(
            'reseller', 'balance', 'totalOrders', 'pendingOrders',
            'processingOrders', 'completedOrders', 'totalSpent', 'recentOrders', 'isOwner'
        ));
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
     * Auto-update order statuses for pending/processing orders
     */
    protected function autoUpdateOrderStatuses($orders)
    {
        $ogaviralService = app(\App\Services\OgaviralService::class);
        
        foreach ($orders as $order) {
            if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
                try {
                    $status = $ogaviralService->getOrderStatus($order->api_order_id);
                    
                    if (isset($status['status'])) {
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
                    \Log::error('Dashboard auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
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
            $reseller = app('current_reseller');
            $resellerOwner = $reseller->owner;
            
            \App\Services\WalletService::refund(
                $order->user, 
                $order->charge, 
                "Auto-refund for Order - Order cancelled by provider",
                'AUTO-REFUND-' . $order->id
            );
            
            if ($order->reseller_id) {
                \App\Services\WalletService::refund(
                    $resellerOwner,
                    $order->charge - $order->profit,
                    "Auto-refund platform cost for cancelled order",
                    'AUTO-PLATFORM-REFUND-' . $order->id
                );
            }

            $order->update(['status' => 'cancelled']);

        } catch (\Exception $e) {
            \Log::error('Dashboard auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
        }
    }
}