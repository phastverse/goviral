<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Traits\ChecksPendingDeposits;
use App\Services\OgaviralService;

class DashboardController extends Controller
{
    use ChecksPendingDeposits;
    /**
     * Show admin dashboard
     */
    public function index(Request $request)
    {
        // CHECK PENDING DEPOSITS (batch of 10 for all users)
        $this->checkAllPendingDeposits(10, 'Admin dashboard');
        
        // Get filter period (default: today)
        $period = $request->get('period', 'today');
        
        // Date ranges based on period
        $dateRange = $this->getDateRange($period);
        
        // CUSTOMER STATISTICS
        $totalCustomers = User::count();
        $newCustomers = User::whereBetween('created_at', $dateRange)->count();
        
        // Customers by period
        $customersToday = User::whereDate('created_at', today())->count();
        $customersWeek = User::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ])->count();
        $customersMonth = User::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $customersYear = User::whereYear('created_at', now()->year)->count();

        // ORDER STATISTICS
        $totalOrders = Order::count();
        $ordersInPeriod = Order::whereBetween('created_at', $dateRange)->count();
        
        // Orders by status in period
        $pendingOrders = Order::where('status', 'pending')
            ->whereBetween('created_at', $dateRange)
            ->count();
        $processingOrders = Order::where('status', 'processing')
            ->whereBetween('created_at', $dateRange)
            ->count();
        $completedOrders = Order::where('status', 'completed')
            ->whereBetween('created_at', $dateRange)
            ->count();
        $cancelledOrders = Order::where('status', 'cancelled')
            ->whereBetween('created_at', $dateRange)
            ->count();
        
        // Orders by period
        $ordersToday = Order::whereDate('created_at', today())->count();
        $ordersWeek = Order::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ])->count();
        $ordersMonth = Order::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $ordersYear = Order::whereYear('created_at', now()->year)->count();

        // Revenue in period
        $revenueInPeriod = Order::whereBetween('created_at', $dateRange)->where('status', 'completed')->sum('charge');
        $revenueToday = Order::whereDate('created_at', today())->where('status', 'completed')->sum('charge');
        $revenueWeek = Order::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ])->where('status', 'completed')->sum('charge');
        $revenueMonth = Order::whereMonth('created_at', now()->month)
        ->where('status', 'completed')
            ->whereYear('created_at', now()->year)
            ->sum('charge');
        $revenueYear = Order::whereYear('created_at', now()->year)->where('status', 'completed')->sum('charge');

        // WALLET STATISTICS
        $totalDeposits = Wallet::where('type', 'credit')
            ->where('status', 'success')
            ->count();

        $depositsInPeriod = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereBetween('created_at', $dateRange)
            ->count();
        
        // Deposits by period
        $depositsToday = Wallet::where('type', 'credit')
            ->where('status', 'success')
            ->whereDate('created_at', today())
            ->count();
        $depositsWeek = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])->count();
        $depositsMonth = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $depositsYear = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereYear('created_at', now()->year)
            ->count();

        // Deposit amounts by period
        $depositAmountToday = Wallet::where('type', 'credit')
            ->whereDate('created_at', today())
            ->where('status', 'success')
            ->sum('amount');
        $depositAmountWeek = Wallet::where('type', 'credit')
            ->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])->where('status', 'success')->sum('amount');
        $depositAmountMonth = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');
        $depositAmountYear = Wallet::where('type', 'credit')
        ->where('status', 'success')
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        // Pending deposits
        $pendingDeposits = Wallet::where('type', 'credit')
            ->where('status', 'pending')
            ->count();
        $pendingDepositAmount = Wallet::where('type', 'credit')
            ->where('status', 'pending')
            ->sum('amount');

        // SUPPORT TICKETS
        $totalTickets = SupportTicket::count();
        $openTickets = SupportTicket::where('status', 'open')->count();
        $closedTickets = SupportTicket::where('status', 'closed')->count();

        // Recent Data
        $recentOrders = Order::with('user')
            ->latest()
            ->take(10)
            ->get();

        $recentCustomers = User::latest()
            ->take(5)
            ->get();

        $recentTransactions = Wallet::with('user')
            ->latest()
            ->take(5)
            ->get();

        // OGAVIRAL API BALANCE
        $ogaviralBalance = null;
        if (auth('admin')->user()->canEditOrders()) {
            $ogaviralService = new OgaviralService();
            $balanceResponse = $ogaviralService->getBalance();
            $ogaviralBalance = $balanceResponse['balance'] ?? null;
        }

        return view('admin.dashboard', compact(
            'ogaviralBalance',
            'period',
            'totalCustomers', 'newCustomers',
            'customersToday', 'customersWeek', 'customersMonth', 'customersYear',
            'totalOrders', 'ordersInPeriod',
            'pendingOrders', 'processingOrders', 'completedOrders', 'cancelledOrders',
            'ordersToday', 'ordersWeek', 'ordersMonth', 'ordersYear',
            'revenueInPeriod', 'revenueToday', 'revenueWeek', 'revenueMonth', 'revenueYear',
            'totalDeposits', 'depositsInPeriod',
            'depositsToday', 'depositsWeek', 'depositsMonth', 'depositsYear',
            'depositAmountToday', 'depositAmountWeek', 'depositAmountMonth', 'depositAmountYear',
            'pendingDeposits', 'pendingDepositAmount',
            'totalTickets', 'openTickets', 'closedTickets',
            'recentOrders', 'recentCustomers', 'recentTransactions'
        ));
    }
 
    /**
     * Get date range based on period
     */
    private function getDateRange($period)
    {
        switch ($period) {
            case 'today':
                return [Carbon::today(), Carbon::tomorrow()];
            
            case 'week':
                return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
            
            case 'month':
                return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
            
            case 'year':
                return [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()];
            
            default:
                return [Carbon::today(), Carbon::tomorrow()];
        }
    }
}