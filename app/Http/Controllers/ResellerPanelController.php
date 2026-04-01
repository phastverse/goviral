<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use App\Models\ResellerUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResellerPanelController extends Controller
{
public function index()
{
    $reseller = Reseller::where('user_id', Auth::id())->first();
    
    if ($reseller) {
        // Load relationships
        $reseller->load('owner');
        
        // Statistics
        $totalRevenue    = $reseller->orders()->sum('charge');
        $totalProfit     = $reseller->orders()->where('status', 'completed')->sum('profit');
        $totalOrders     = $reseller->orders()->count();
        $totalCustomers  = $reseller->resellerUsers()->count();
        
        // Get customers with their order stats
        $customers = $reseller->resellerUsers()
            ->with(['user' => function($query) use ($reseller) {
                $query->withCount(['orders' => function($q) use ($reseller) {
                    $q->where('reseller_id', $reseller->id);
                }]);
            }])
            ->latest()
            ->take(10)
            ->get()
            ->map(function($resellerUser) {
                $user = $resellerUser->user;
                $user->total_spent = $user->orders()->where('reseller_id', $resellerUser->reseller_id)->sum('charge');
                return $user;
            });
        
        // Recent orders
        $recentOrders = $reseller->orders()
            ->with('user')
            ->latest()
            ->take(10)
            ->get();
        
        // Recent transactions
        $recentTransactions = \App\Models\Wallet::whereIn('user_id', 
            $reseller->resellerUsers()->pluck('user_id')
        )
        ->with('user')
        ->latest()
        ->take(10)
        ->get();
        
        return view('reseller-panel.index', compact(
            'reseller', 'totalRevenue', 'totalProfit', 
            'totalOrders', 'totalCustomers', 'customers', 
            'recentOrders', 'recentTransactions'
        ));
    }
    
    return view('reseller-panel.index', compact('reseller'));
}
    public function create()
    {
        // If user already has a panel, redirect to manage it
        if (Reseller::where('user_id', Auth::id())->exists()) {
            return redirect()->route('reseller-panel.index');
        }

        return view('reseller-panel.create');
    }

    public function store(Request $request)
    {
        if (Reseller::where('user_id', Auth::id())->exists()) {
            return redirect()->route('reseller-panel.index')->with('alert', [
                'type'    => 'error',
                'message' => 'You already have a reseller panel.',
            ]);
        }

        $request->validate([
            'subdomain'              => [
                'required', 'string', 'alpha_dash', 'min:3', 'max:32',
                'unique:resellers,subdomain',
                'not_in:www,api,admin,mail,ftp,smtp,panel,app,dashboard',
            ],
            'panel_name'             => 'required|string|max:100',
            'default_markup_percent' => 'required|numeric|min:1|max:200',
            'primary_color'          => 'nullable|string|max:7',
            'support_email'          => 'nullable|email|max:100',
        ]);

        $reseller = Reseller::create([
            'user_id'                => Auth::id(),
            'subdomain'              => strtolower(trim($request->subdomain)),
            'panel_name'             => $request->panel_name,
            'default_markup_percent' => $request->default_markup_percent,
            'primary_color'          => $request->primary_color ?? '#6366f1',
            'support_email'          => $request->support_email,
            'status'                 => 'pending', // Admin must approve
        ]);

        return redirect()->route('reseller-panel.index')->with('alert', [
            'type'    => 'success',
            'message' => 'Panel created! Your subdomain is ' . $reseller->subdomain . '.' . config('app.base_domain') . '. It will go live once approved.',
        ]);
    }

    public function update(Request $request)
    {
        $reseller = Reseller::where('user_id', Auth::id())->firstOrFail();

        $request->validate([
            'panel_name'             => 'required|string|max:100',
            'default_markup_percent' => 'required|numeric|min:1|max:200',
            'primary_color'          => 'nullable|string|max:7',
            'support_email'          => 'nullable|email|max:100',
            'custom_domain'          => 'nullable|string|max:100',
        ]);

        $reseller->update([
            'panel_name'             => $request->panel_name,
            'default_markup_percent' => $request->default_markup_percent,
            'primary_color'          => $request->primary_color ?? $reseller->primary_color,
            'support_email'          => $request->support_email,
            'custom_domain'          => $request->custom_domain ?? null,
        ]);

        return back()->with('alert', [
            'type'    => 'success',
            'message' => 'Panel settings updated successfully.',
        ]);
    }

public function updateDomain(Request $request)
{
    // Get the reseller first
    $reseller = Reseller::where('user_id', auth()->id())->firstOrFail();
    
    // Validate with a custom rule that ignores the current reseller's ID
    $request->validate([
        'custom_domain' => [
            'nullable',
            'string',
            'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i',
            'max:255',
            function ($attribute, $value, $fail) use ($reseller) {
                if ($value && Reseller::where('custom_domain', $value)
                    ->where('id', '!=', $reseller->id)
                    ->exists()) {
                    $fail('This domain is already in use by another reseller.');
                }
            }
        ],
    ]);
    
    // Rest of your code remains the same...
    if (!$request->custom_domain) {
        $reseller->update([
            'custom_domain' => null,
            'custom_domain_status' => 'pending',
            'custom_domain_verified_at' => null,
            'custom_domain_error' => null,
        ]);
        
        return redirect()->back()->with('alert', [
            'type' => 'success',
            'message' => 'Custom domain removed. Your panel will use the subdomain.',
        ]);
    }
    
    // Verify domain DNS
    $verification = $this->verifyDomainDNS($request->custom_domain);
    
    if ($verification['verified']) {
        $reseller->update([
            'custom_domain' => $request->custom_domain,
            'custom_domain_status' => 'active',
            'custom_domain_verified_at' => now(),
            'custom_domain_error' => null,
        ]);
        
        return redirect()->back()->with('alert', [
            'type' => 'success',
            'message' => 'Domain verified and saved! Your panel will be available at https://' . $request->custom_domain . ' once DNS propagates.',
        ]);
    } else {
        $reseller->update([
            'custom_domain' => $request->custom_domain,
            'custom_domain_status' => 'failed',
            'custom_domain_error' => $verification['error'],
        ]);
        
        return redirect()->back()->with('alert', [
            'type' => 'error',
            'message' => 'Domain verification failed: ' . $verification['error'],
        ]);
    }
}

    private function verifyDomainDNS($domain)
    {
        // Get server IP
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        
        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);
        
        try {
            // Check A record
            $dnsRecords = dns_get_record($domain, DNS_A);
            
            if (empty($dnsRecords)) {
                return [
                    'verified' => false,
                    'error' => 'No A record found for this domain. Please add an A record pointing to ' . $serverIp
                ];
            }
            
            $found = false;
            foreach ($dnsRecords as $record) {
                if ($record['ip'] === $serverIp) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $currentIp = $dnsRecords[0]['ip'] ?? 'unknown';
                return [
                    'verified' => false,
                    'error' => "A record points to {$currentIp}, but should point to {$serverIp}"
                ];
            }
            
            // Optional: Check if the domain resolves to our server
            $resolvedIp = gethostbyname($domain);
            if ($resolvedIp !== $serverIp && $resolvedIp !== $domain) {
                return [
                    'verified' => false,
                    'error' => "Domain resolves to {$resolvedIp}, but should resolve to {$serverIp}"
                ];
            }
            
            return [
                'verified' => true,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            return [
                'verified' => false,
                'error' => 'DNS lookup failed'
            ];
        }
    }

    public function verifyDomain(Request $request)
    {
        $reseller = Reseller::where('user_id', auth()->id())->firstOrFail();
        
        if (!$reseller->custom_domain) {
            return response()->json(['verified' => false, 'message' => 'No custom domain configured']);
        }
        
        $verification = $this->verifyDomainDNS($reseller->custom_domain);
        
        if ($verification['verified']) {
            $reseller->update([
                'custom_domain_status' => 'active',
                'custom_domain_verified_at' => now(),
                'custom_domain_error' => null,
            ]);
        } else {
            $reseller->update([
                'custom_domain_status' => 'failed',
                'custom_domain_error' => $verification['error'],
            ]);
        }
        
        return response()->json($verification);
    }

    
}