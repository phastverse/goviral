<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use App\Models\ResellerUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResellerPanelController extends Controller
{
    public function index()
    {
        $reseller = Reseller::where('user_id', Auth::id())->first();
        
        if ($reseller) {
            $reseller->load('owner');
            
            $totalRevenue   = $reseller->orders()->sum('charge');
            $totalProfit    = $reseller->orders()->where('status', 'completed')->sum('profit');
            $totalOrders    = $reseller->orders()->count();
            $totalCustomers = $reseller->resellerUsers()->count();
            
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
                    $user->total_spent = $user->orders()
                        ->where('reseller_id', $resellerUser->reseller_id)
                        ->sum('charge');
                    return $user;
                });
            
            $recentOrders = $reseller->orders()
                ->with('user')
                ->latest()
                ->take(10)
                ->get();
            
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
            'subdomain' => [
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
            'status'                 => 'pending',
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
        $reseller = Reseller::where('user_id', auth()->id())->firstOrFail();

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

        // Removing custom domain
        if (!$request->custom_domain) {
            // Remove from cPanel too
            if ($reseller->custom_domain) {
                $this->removeDomainFromCpanel($reseller->custom_domain);
            }

            $reseller->update([
                'custom_domain'             => null,
                'custom_domain_status'      => null,
                'custom_domain_verified_at' => null,
                'custom_domain_error'       => null,
            ]);

            return redirect()->back()->with('alert', [
                'type'    => 'success',
                'message' => 'Custom domain removed. Your panel will use the subdomain.',
            ]);
        }

        // Verify DNS first
        $verification = $this->verifyDomainDNS($request->custom_domain);

        if ($verification['verified']) {

            // Add to cPanel as addon domain
            $cpanelResult = $this->addDomainToCpanel($request->custom_domain);

            $reseller->update([
                'custom_domain'             => $request->custom_domain,
                'custom_domain_status'      => 'active',
                'custom_domain_verified_at' => now(),
                'custom_domain_error'       => null,
            ]);

            $message = 'Domain verified and saved! Your panel will be available at https://' . $request->custom_domain;

            if (!$cpanelResult['success']) {
                // Domain is verified in DNS but cPanel had an issue — log it, don't block the user
                Log::warning('cPanel addon domain failed for ' . $request->custom_domain, $cpanelResult);
                $message .= ' (Note: SSL setup may take a little longer — our team has been notified.)';
            }

            return redirect()->back()->with('alert', [
                'type'    => 'success',
                'message' => $message,
            ]);

        } else {

            $reseller->update([
                'custom_domain'        => $request->custom_domain,
                'custom_domain_status' => 'failed',
                'custom_domain_error'  => $verification['error'],
            ]);

            return redirect()->back()->with('alert', [
                'type'    => 'error',
                'message' => 'Domain verification failed: ' . $verification['error'],
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // cPanel API Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add a custom domain as a cPanel Addon Domain pointing to this Laravel app.
     */
    private function addDomainToCpanel(string $domain): array
    {
        $cpanelUser  = env('CPANEL_USERNAME');
        $cpanelToken = env('CPANEL_API_TOKEN');
        $cpanelHost  = env('CPANEL_HOST');
        $docRoot     = env('CPANEL_DOC_ROOT', 'public_html/public');

        if (!$cpanelUser || !$cpanelToken || !$cpanelHost) {
            Log::warning('cPanel credentials not configured in .env');
            return ['success' => false, 'error' => 'cPanel credentials not configured.'];
        }

        // cPanel needs a subdomain label — use the domain with dots replaced
        $subdomainLabel = str_replace(['.', '-'], '_', $domain);

        try {
            $response = Http::withHeaders([
                'Authorization' => "cpanel {$cpanelUser}:{$cpanelToken}",
            ])
            ->timeout(15)
            ->post("https://{$cpanelHost}:2083/execute/AddonDomain/add_addon_domain", [
                'newdomain' => $domain,
                'subdomain' => $subdomainLabel,
                'dir'       => $docRoot,
            ]);

            $body = $response->json();

            Log::info('cPanel add_addon_domain response', [
                'domain'   => $domain,
                'response' => $body,
            ]);

            // cPanel returns errors array — check it
            if (!empty($body['errors'])) {
                // "Domain already exists" is not a real error for us
                $errors = implode(', ', $body['errors']);
                if (str_contains(strtolower($errors), 'already exists')) {
                    return ['success' => true, 'note' => 'Domain already existed in cPanel.'];
                }
                return ['success' => false, 'error' => $errors];
            }

            // Trigger AutoSSL after adding the domain
            $this->triggerAutoSSL($domain);

            return ['success' => true];

        } catch (\Exception $e) {
            Log::error('cPanel addon domain exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove a custom domain from cPanel when the reseller removes it.
     */
    private function removeDomainFromCpanel(string $domain): void
    {
        $cpanelUser  = env('CPANEL_USERNAME');
        $cpanelToken = env('CPANEL_API_TOKEN');
        $cpanelHost  = env('CPANEL_HOST');

        if (!$cpanelUser || !$cpanelToken || !$cpanelHost) return;

        $subdomainLabel = str_replace(['.', '-'], '_', $domain);

        try {
            $response = Http::withHeaders([
                'Authorization' => "cpanel {$cpanelUser}:{$cpanelToken}",
            ])
            ->timeout(15)
            ->post("https://{$cpanelHost}:2083/execute/AddonDomain/remove_addon_domain", [
                'domain'    => $domain,
                'subdomain' => $subdomainLabel,
            ]);

            Log::info('cPanel remove_addon_domain response', [
                'domain'   => $domain,
                'response' => $response->json(),
            ]);

        } catch (\Exception $e) {
            Log::error('cPanel remove addon domain exception: ' . $e->getMessage());
        }
    }

    /**
     * Trigger cPanel AutoSSL to issue a certificate for the new domain.
     */
    private function triggerAutoSSL(string $domain): void
    {
        $cpanelUser  = env('CPANEL_USERNAME');
        $cpanelToken = env('CPANEL_API_TOKEN');
        $cpanelHost  = env('CPANEL_HOST');

        if (!$cpanelUser || !$cpanelToken || !$cpanelHost) return;

        try {
            Http::withHeaders([
                'Authorization' => "cpanel {$cpanelUser}:{$cpanelToken}",
            ])
            ->timeout(15)
            ->post("https://{$cpanelHost}:2083/execute/SSL/start_autossl_check_for_domain", [
                'domain' => $domain,
            ]);

            Log::info('cPanel AutoSSL triggered for: ' . $domain);

        } catch (\Exception $e) {
            Log::error('cPanel AutoSSL trigger exception: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DNS Verification
    // ─────────────────────────────────────────────────────────────────────────

    private function verifyDomainDNS(string $domain): array
    {
        $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        $domain   = preg_replace('#^https?://#', '', $domain);

        try {
            $dnsRecords = dns_get_record($domain, DNS_A);

            if (empty($dnsRecords)) {
                return [
                    'verified' => false,
                    'error'    => 'No A record found for this domain. Please add an A record pointing to ' . $serverIp,
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
                    'error'    => "A record points to {$currentIp}, but should point to {$serverIp}",
                ];
            }

            $resolvedIp = gethostbyname($domain);
            if ($resolvedIp !== $serverIp && $resolvedIp !== $domain) {
                return [
                    'verified' => false,
                    'error'    => "Domain resolves to {$resolvedIp}, but should resolve to {$serverIp}",
                ];
            }

            return ['verified' => true, 'error' => null];

        } catch (\Exception $e) {
            return ['verified' => false, 'error' => 'DNS lookup failed'];
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
                'custom_domain_status'      => 'active',
                'custom_domain_verified_at' => now(),
                'custom_domain_error'       => null,
            ]);

            // Re-trigger AutoSSL in case it wasn't done before
            $this->triggerAutoSSL($reseller->custom_domain);

        } else {
            $reseller->update([
                'custom_domain_status' => 'failed',
                'custom_domain_error'  => $verification['error'],
            ]);
        }

        return response()->json($verification);
    }
}