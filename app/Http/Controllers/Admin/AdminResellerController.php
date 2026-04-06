<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $totalRevenue     = $reseller->orders()->sum('charge');
        $totalProfit      = $reseller->orders()->where('status', 'completed')->sum('profit');
        $totalOrders      = $reseller->orders()->count();
        $totalCustomers   = $reseller->resellerUsers()->count();
        $recentOrders     = $reseller->orders()->with('user')->latest()->take(5)->get();
        $ownerBalance     = $reseller->owner->balance;
        $detectedServerIp = $this->getServerIp();

        return view('admin.resellers.show', compact(
            'reseller', 'totalRevenue', 'totalProfit',
            'totalOrders', 'totalCustomers', 'recentOrders',
            'ownerBalance', 'detectedServerIp'
        ));
    }

    public function approve(Reseller $reseller)
    {
        $serverIp = $this->getServerIp();

        $reseller->update([
            'status'           => 'active',
            'server_ip'        => $serverIp,
            'approved_at'      => now(),
            'rejection_reason' => null,
        ]);

        // If reseller already has a custom domain saved, add it to cPanel now
        if ($reseller->custom_domain) {
            $result = $this->addDomainToCpanel($reseller->custom_domain);
            if (!$result['success']) {
                Log::warning('cPanel addon failed on approve for ' . $reseller->custom_domain, $result);
            }
        }

        // Notify reseller owner
        // $reseller->owner->notify(new PanelApprovedNotification($reseller));

        return redirect()->route('admin.resellers.show', $reseller)->with('alert', [
            'type'    => 'success',
            'message' => 'Panel approved! Server IP ' . $serverIp . ' assigned.',
        ]);
    }

    public function reject(Request $request, Reseller $reseller)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $reseller->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'approved_at'      => null,
        ]);

        // $reseller->owner->notify(new PanelRejectedNotification($reseller, $request->rejection_reason));

        return redirect()->route('admin.resellers.show', $reseller)->with('alert', [
            'type'    => 'warning',
            'message' => 'Panel rejected. Reason: ' . $request->rejection_reason,
        ]);
    }

    public function updateStatus(Request $request, Reseller $reseller)
    {
        $request->validate(['status' => 'required|in:active,suspended,pending']);

        // If activating and no server IP yet, assign one
        if ($request->status === 'active' && !$reseller->server_ip) {
            $reseller->server_ip  = $this->getServerIp();
            $reseller->approved_at = now();

            // Also add custom domain to cPanel if it exists
            if ($reseller->custom_domain) {
                $this->addDomainToCpanel($reseller->custom_domain);
            }
        }

        // If suspending and they have a custom domain, remove from cPanel
        if ($request->status === 'suspended' && $reseller->custom_domain) {
            $this->removeDomainFromCpanel($reseller->custom_domain);
        }

        $reseller->status = $request->status;
        $reseller->save();

        return back()->with('alert', [
            'type'    => 'success',
            'message' => 'Status updated to ' . $request->status,
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

    // ─────────────────────────────────────────────────────────────────────────
    // cPanel API Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add a domain as cPanel Addon Domain pointing to the Laravel public folder.
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

            if (!empty($body['errors'])) {
                $errors = implode(', ', $body['errors']);
                // "Already exists" is fine — domain is already registered
                if (str_contains(strtolower($errors), 'already exists')) {
                    $this->triggerAutoSSL($domain);
                    return ['success' => true, 'note' => 'Domain already existed in cPanel.'];
                }
                return ['success' => false, 'error' => $errors];
            }

            // Trigger AutoSSL to issue free SSL cert
            $this->triggerAutoSSL($domain);

            return ['success' => true];

        } catch (\Exception $e) {
            Log::error('cPanel addDomainToCpanel exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove an addon domain from cPanel (e.g. when suspended or domain removed).
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
            Log::error('cPanel removeDomainFromCpanel exception: ' . $e->getMessage());
        }
    }

    /**
     * Trigger cPanel AutoSSL to issue a free SSL cert for the domain.
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
    // Server IP Detection
    // ─────────────────────────────────────────────────────────────────────────

    private function getServerIp(): string
    {
        // Method 1: Server variable (most reliable on dedicated/VPS)
        if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
            return $_SERVER['SERVER_ADDR'];
        }

        // Method 2: Hostname resolution
        $hostname = gethostname();
        $ip = gethostbyname($hostname);
        if ($ip && $ip !== $hostname && $ip !== '127.0.0.1') {
            return $ip;
        }

        // Method 3: External API — most reliable on shared/cPanel hosting
        try {
            $publicIp = trim(file_get_contents('https://api.ipify.org'));
            if (filter_var($publicIp, FILTER_VALIDATE_IP)) {
                return $publicIp;
            }
        } catch (\Exception $e) {
            //
        }

        // Method 4: ENV override — set SERVER_IP=x.x.x.x in .env if all else fails
        if (env('SERVER_IP')) {
            return env('SERVER_IP');
        }

        return 'Contact support for server IP';
    }
}