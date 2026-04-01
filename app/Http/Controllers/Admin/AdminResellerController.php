<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminResellerController extends Controller
{
    public function index()
    {
        $resellers = Reseller::with('owner')
            ->withCount('orders')
            ->latest()
            ->paginate(20);

        return view('admin.resellers.index', compact('resellers'));
    }

    public function show(Reseller $reseller)
    {
        $reseller->load('owner');

        $totalRevenue    = $reseller->orders()->sum('charge');
        $totalProfit     = $reseller->orders()->where('status', 'completed')->sum('profit');
        $totalOrders     = $reseller->orders()->count();
        $totalCustomers  = $reseller->resellerUsers()->count();
        $recentOrders    = $reseller->orders()->with('user')->latest()->take(5)->get();
        $ownerBalance    = $reseller->owner->balance;
        
        // Auto-detect server IP for display
        $detectedServerIp = $this->getServerIp();

        return view('admin.resellers.show', compact(
            'reseller', 'totalRevenue', 'totalProfit',
            'totalOrders', 'totalCustomers', 'recentOrders', 
            'ownerBalance', 'detectedServerIp'
        ));
    }
    
    public function approve(Reseller $reseller)
    {
        // Auto-detect server IP
        $serverIp = $this->getServerIp();
        
        $reseller->update([
            'status' => 'active',
            'server_ip' => $serverIp,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return redirect()->route('admin.resellers.show', $reseller)->with('alert', [
            'type' => 'success',
            'message' => 'Panel approved! Server IP: ' . $serverIp . ' has been assigned.',
        ]);
    }

    public function updateStatus(Request $request, Reseller $reseller)
    {
        $request->validate(['status' => 'required|in:active,suspended,pending']);
        
        // If activating, make sure server IP is set
        if ($request->status === 'active' && !$reseller->server_ip) {
            $serverIp = $this->getServerIp();
            $reseller->server_ip = $serverIp;
            $reseller->approved_at = now();
        }
        
        $reseller->status = $request->status;
        $reseller->save();

        return back()->with('alert', [
            'type' => 'success',
            'message' => 'Status updated to ' . $request->status,
        ]);
    }

    /**
     * Auto-detect the server's public IP address
     */
    private function getServerIp()
    {
        // Method 1: Get from server variables (works on most hosting)
        if (isset($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }
        
        // Method 2: Get from gethostname (works on many servers)
        $hostname = gethostname();
        $ip = gethostbyname($hostname);
        if ($ip && $ip !== $hostname) {
            return $ip;
        }
        
        // Method 3: Use external API to get public IP (for shared hosting)
        try {
            $publicIp = file_get_contents('https://api.ipify.org');
            if (filter_var($publicIp, FILTER_VALIDATE_IP)) {
                return $publicIp;
            }
        } catch (\Exception $e) {
            // Fallback to localhost
        }
        
        // Method 4: Get from environment variable (if set)
        if (env('SERVER_IP')) {
            return env('SERVER_IP');
        }
        
        // Final fallback - but this should rarely happen
        return 'Contact support for server IP';
    }
    public function reject(Request $request, Reseller $reseller)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $reseller->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'approved_at' => null,
        ]);

        // Send notification to reseller
        // $reseller->owner->notify(new PanelRejectedNotification($reseller, $request->rejection_reason));

        return redirect()->route('admin.resellers.show', $reseller)->with('alert', [
            'type' => 'warning',
            'message' => 'Panel rejected. Reason: ' . $request->rejection_reason,
        ]);
    }

    public function customers(Reseller $reseller)
    {
        $customers = $reseller->resellerUsers()
            ->with('user')
            ->latest()
            ->paginate(30);

        return view('admin.resellers.customers', compact('reseller', 'customers'));
    }

    public function orders(Reseller $reseller)
    {
        $orders = $reseller->orders()
            ->with('user')
            ->latest()
            ->paginate(30);

        $totalCharge = $reseller->orders()->sum('charge');
        $totalProfit = $reseller->orders()->sum('profit');

        return view('admin.resellers.orders', compact('reseller', 'orders', 'totalCharge', 'totalProfit'));
    }

    public function wallet(Reseller $reseller)
    {
        $owner = $reseller->owner;

        $transactions = \App\Models\Wallet::where('user_id', $owner->id)
            ->latest()
            ->paginate(30);

        return view('admin.resellers.wallet', compact('reseller', 'owner', 'transactions'));
    }
}