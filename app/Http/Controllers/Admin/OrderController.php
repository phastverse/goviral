<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Provider;
use App\Models\Wallet;
use App\Models\Logged;
use App\Models\User;
use App\Services\ProviderService;
use Illuminate\Http\Request;
use App\Traits\LogsAdminActivity;

class OrderController extends Controller
{
    use LogsAdminActivity;

    protected ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    public function index(Request $request)
    {
        $query = Order::with(['user', 'provider']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('service_name', 'like', "%{$search}%")
                  ->orWhere('link', 'like', "%{$search}%")
                  ->orWhere('api_order_id', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$search}%")
                                                      ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('provider_id')) $query->where('provider_id', $request->provider_id);
        if ($request->filled('date_from'))   $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('created_at', '<=', $request->date_to);
        if ($request->filled('amount_min'))  $query->where('charge', '>=', $request->amount_min);
        if ($request->filled('amount_max'))  $query->where('charge', '<=', $request->amount_max);

        $orders = $query->latest()->paginate(20)->withQueryString();

        // Auto-update statuses
        $this->autoUpdateOrderStatuses($orders);

        // Stats (same filters)
        $statsQuery = Order::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $statsQuery->where(fn($q) => $q->where('id','like',"%$s%")->orWhere('service_name','like',"%$s%")->orWhere('link','like',"%$s%"));
        }
        if ($request->filled('status'))      $statsQuery->where('status', $request->status);
        if ($request->filled('provider_id')) $statsQuery->where('provider_id', $request->provider_id);
        if ($request->filled('date_from'))   $statsQuery->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $statsQuery->whereDate('created_at', '<=', $request->date_to);

        $totalOrders      = (clone $statsQuery)->count();
        $pendingOrders    = (clone $statsQuery)->where('status', 'pending')->count();
        $processingOrders = (clone $statsQuery)->where('status', 'processing')->count();
        $completedOrders  = (clone $statsQuery)->where('status', 'completed')->count();
        $cancelledOrders  = (clone $statsQuery)->where('status', 'cancelled')->count();
        $totalRevenue     = (clone $statsQuery)->where('status', 'completed')->sum('charge');

        $this->logActivity('viewed', auth('admin')->user()->name . ' viewed orders list', 'Order', null);

        return view('admin.orders.index', compact(
            'orders', 'totalOrders', 'pendingOrders', 'processingOrders',
            'completedOrders', 'cancelledOrders', 'totalRevenue'
        ));
    }

    public function show($id)
    {
        $order = Order::with(['user', 'provider'])->findOrFail($id);

        $this->autoUpdateSingleOrder($order);
        $order->refresh();

        $logs = Logged::where('user_id', $order->user_id)
            ->where(fn($q) => $q->where('reference', $order->id)
                ->orWhere('description', 'like', '%Order #' . substr($order->id, 0, 8) . '%'))
            ->latest()->paginate(10);

        $latestWallet    = Wallet::where('user_id', $order->user_id)->orderByDesc('created_at')->first();
        $customerBalance = $latestWallet ? $latestWallet->balance_after : 0;

        $this->logViewed('Order', $order->id, auth('admin')->user()->name . ' viewed Order #' . substr($order->id, 0, 8));

        return view('admin.orders.show', compact('order', 'logs', 'customerBalance'));
    }

    public function checkStatus($id)
    {
        $order = Order::with(['user', 'provider'])->findOrFail($id);

        if (!$order->api_order_id) {
            return back()->with('error', 'No API order ID found for this order.');
        }

        try {
            $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);

            if (isset($status['status'])) {
                $oldStatus = $order->status;
                $newStatus = $this->mapApiStatus($status['status']);

                if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                    $this->processAutoRefund($order, $oldStatus, $newStatus);
                    return back()->with('success', 'Order cancelled and refunded. ₦' . number_format($order->charge, 2) . ' credited to wallet.');
                }

                $order->update(['status' => $newStatus, 'api_response' => json_encode($status)]);

                $this->logUpdated('Order', $order->id,
                    auth('admin')->user()->name . ' checked status: ' . $oldStatus . ' → ' . $newStatus,
                    ['status' => ['old' => $oldStatus, 'new' => $newStatus]]
                );

                Logged::create([
                    'user_id'     => $order->user_id,
                    'reference'   => $order->id,
                    'type'        => 'order',
                    'method'      => 'manual_status_check',
                    'amount'      => $order->charge,
                    'status'      => 'success',
                    'description' => "Order #" . substr($order->id, 0, 8) . " manually checked by admin — status: {$newStatus}",
                    'ip_address'  => request()->ip(),
                ]);

                return back()->with('success', 'Status updated: ' . ucfirst($newStatus));
            }

            return back()->with('error', 'Failed to fetch status from API.');

        } catch (\Exception $e) {
            \Log::error('Admin manual status check failed: ' . $e->getMessage());
            return back()->with('error', 'Failed: ' . $e->getMessage());
        }
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
                            Logged::create([
                                'user_id'     => $order->user_id,
                                'reference'   => $order->id,
                                'type'        => 'order',
                                'method'      => 'auto_status_update',
                                'amount'      => $order->charge,
                                'status'      => 'completed',
                                'description' => "Order #" . substr($order->id, 0, 8) . " auto-updated: {$oldStatus} → {$newStatus}",
                                'ip_address'  => request()->ip(),
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Auto status update failed for order ' . $order->id . ': ' . $e->getMessage());
                }
            }
        }
    }

    protected function autoUpdateSingleOrder($order)
    {
        if (in_array($order->status, ['pending', 'processing']) && $order->api_order_id) {
            try {
                $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);
                if (isset($status['status'])) {
                    $newStatus = $this->mapApiStatus($status['status']);
                    if ($order->status !== $newStatus) {
                        $oldStatus = $order->status;
                        if ($this->shouldAutoRefund($oldStatus, $newStatus)) {
                            $this->processAutoRefund($order, $oldStatus, $newStatus);
                            return;
                        }
                        $order->update(['status' => $newStatus, 'api_response' => json_encode($status)]);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Auto update failed for order ' . $order->id . ': ' . $e->getMessage());
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
            $user         = $order->user;
            $latestWallet = Wallet::where('user_id', $order->user_id)->orderByDesc('created_at')->first();
            $balance      = $latestWallet ? $latestWallet->balance_after : 0;

            $wallet = Wallet::create([
                'user_id'        => $order->user_id,
                'balance_before' => $balance,
                'amount'         => $order->charge,
                'balance_after'  => $balance + $order->charge,
                'type'           => 'credit',
                'description'    => "Auto-refund for Order #" . substr($order->id, 0, 8) . " — cancelled by provider",
                'reference'      => 'REFUND-' . strtoupper(uniqid()),
                'payment_method' => 'refund',
                'status'         => 'success',
            ]);

            $user->increment('balance', $order->charge);
            $order->update(['status' => 'cancelled']);

            $this->logActivity('auto_refunded',
                'Auto-refund: Order #' . substr($order->id, 0, 8) . ' — ₦' . number_format($order->charge, 2) . ' → ' . $user->name,
                'Order', $order->id, ['refund_amount' => $order->charge, 'old_status' => $oldStatus]
            );

            Logged::create([
                'user_id'     => $order->user_id,
                'reference'   => $wallet->reference,
                'type'        => 'wallet',
                'method'      => 'auto_refund',
                'amount'      => $order->charge,
                'status'      => 'success',
                'description' => "Auto-refund for Order #" . substr($order->id, 0, 8) . " — provider cancelled",
                'ip_address'  => request()->ip(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Auto-refund failed for order ' . $order->id . ': ' . $e->getMessage());
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

    public function updateStatus(Request $request, $id)
    {
        if (!auth('admin')->user()->canEditOrders()) abort(403);

        $order     = Order::with('user')->findOrFail($id);
        $oldStatus = $order->status;

        $request->validate(['status' => 'required|in:pending,processing,completed,cancelled,refunded']);
        $order->update(['status' => $request->status]);

        $this->logUpdated('Order', $order->id,
            auth('admin')->user()->name . ' updated Order #' . substr($order->id, 0, 8) . ": {$oldStatus} → {$request->status}",
            ['status' => ['old' => $oldStatus, 'new' => $request->status]]
        );

        Logged::create([
            'user_id'     => $order->user_id,
            'reference'   => $order->id,
            'type'        => 'order',
            'method'      => 'status_update',
            'amount'      => $order->charge,
            'status'      => 'success',
            'description' => "Order #" . substr($order->id, 0, 8) . " status: {$oldStatus} → {$request->status} by admin",
            'ip_address'  => $request->ip(),
        ]);

        return back()->with('success', 'Order status updated.');
    }

    public function refund(Request $request, $id)
    {
        if (!auth('admin')->user()->canEditOrders()) abort(403);

        $order = Order::with('user')->findOrFail($id);
        if ($order->status === 'completed') return back()->with('error', 'Cannot refund completed orders.');

        $user         = $order->user;
        $latestWallet = Wallet::where('user_id', $order->user_id)->orderByDesc('created_at')->first();
        $balance      = $latestWallet ? $latestWallet->balance_after : 0;

        $wallet = Wallet::create([
            'user_id'        => $order->user_id,
            'balance_before' => $balance,
            'amount'         => $order->charge,
            'balance_after'  => $balance + $order->charge,
            'type'           => 'credit',
            'description'    => "Refund for Order #" . substr($order->id, 0, 8),
            'reference'      => 'REFUND-' . $order->id,
            'payment_method' => 'refund',
            'status'         => 'success',
        ]);

        $user->increment('balance', $order->charge);
        $oldStatus = $order->status;
        $order->update(['status' => 'cancelled']);

        $this->logActivity('refunded',
            auth('admin')->user()->name . ' refunded Order #' . substr($order->id, 0, 8) . ' — ₦' . number_format($order->charge, 2),
            'Order', $order->id
        );

        Logged::create([
            'user_id'     => $order->user_id,
            'reference'   => $wallet->reference,
            'type'        => 'wallet',
            'method'      => 'refund',
            'amount'      => $order->charge,
            'status'      => 'success',
            'description' => "Refund for Order #" . substr($order->id, 0, 8) . " by admin",
            'ip_address'  => $request->ip(),
        ]);

        return back()->with('success', '₦' . number_format($order->charge, 2) . ' refunded to wallet.');
    }

    public function destroy($id)
    {
        if (!auth('admin')->user()->canDeleteOrders()) abort(403);

        $order = Order::with('user')->findOrFail($id);
        if (in_array($order->status, ['pending', 'processing'])) {
            return back()->with('error', 'Cannot delete active orders.');
        }

        $this->logDeleted('Order', $order->id,
            auth('admin')->user()->name . ' deleted Order #' . substr($order->id, 0, 8)
        );

        $order->delete();

        return redirect()->route('admin.orders.index')->with('success', 'Order deleted.');
    }
}