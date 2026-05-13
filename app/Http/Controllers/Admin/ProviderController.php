<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\ProviderService;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    protected ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    // ─── List all providers ───────────────────────────────────────────────────

    public function index()
    {
        $providers = Provider::orderBy('priority')->orderBy('name')->get();

        // Refresh stale balances in the background (only if older than 10 min)
        foreach ($providers as $provider) {
            if ($provider->is_active && $provider->isBalanceStale()) {
                try {
                    $this->providerService->refreshBalance($provider);
                    $provider->refresh(); // pick up new cached_balance
                } catch (\Exception $e) {
                    // Non-fatal – just show stale value
                }
            }
        }

        return view('admin.providers.index', compact('providers'));
    }

    // ─── Create form ──────────────────────────────────────────────────────────

    public function create()
    {
        return view('admin.providers.create');
    }

    // ─── Store new provider ───────────────────────────────────────────────────

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'api_url'   => 'required|url|max:255',
            'api_key'   => 'required|string|max:255',
            'priority'  => 'required|integer|min:1|max:100',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string|max:500',
        ]);

        $provider = Provider::create([
            'name'      => $request->name,
            'api_url'   => $request->api_url,
            'api_key'   => $request->api_key,
            'priority'  => $request->priority,
            'is_active' => $request->boolean('is_active', true),
            'notes'     => $request->notes,
        ]);

        // Try to fetch initial balance
        try {
            $this->providerService->refreshBalance($provider);
        } catch (\Exception $e) {
            // Provider might be valid but unreachable now – that's okay
        }

        return redirect()->route('admin.providers.index')
            ->with('success', "Provider [{$provider->name}] added successfully.");
    }

    // ─── Edit form ────────────────────────────────────────────────────────────

    public function edit(Provider $provider)
    {
        return view('admin.providers.edit', compact('provider'));
    }

    // ─── Update provider ──────────────────────────────────────────────────────

    public function update(Request $request, Provider $provider)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'api_url'   => 'required|url|max:255',
            'api_key'   => 'required|string|max:255',
            'priority'  => 'required|integer|min:1|max:100',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string|max:500',
        ]);

        $provider->update([
            'name'      => $request->name,
            'api_url'   => $request->api_url,
            'api_key'   => $request->api_key,
            'priority'  => $request->priority,
            'is_active' => $request->boolean('is_active'),
            'notes'     => $request->notes,
        ]);

        return redirect()->route('admin.providers.index')
            ->with('success', "Provider [{$provider->name}] updated.");
    }

    // ─── Delete provider ──────────────────────────────────────────────────────

    public function destroy(Provider $provider)
    {
        $name = $provider->name;
        $provider->delete();

        return redirect()->route('admin.providers.index')
            ->with('success', "Provider [{$name}] deleted.");
    }

    // ─── Toggle active/inactive ───────────────────────────────────────────────

    public function toggle(Provider $provider)
    {
        $provider->update(['is_active' => !$provider->is_active]);

        $state = $provider->is_active ? 'enabled' : 'disabled';

        return redirect()->route('admin.providers.index')
            ->with('success', "Provider [{$provider->name}] {$state}.");
    }

    // ─── Refresh balance for one provider ────────────────────────────────────

    public function refreshBalance(Provider $provider)
    {
        try {
            $balance = $this->providerService->refreshBalance($provider);

            if ($balance !== null) {
                return redirect()->route('admin.providers.index')
                    ->with('success', "[{$provider->name}] balance refreshed: ₦" . number_format($balance, 2));
            }

            return redirect()->route('admin.providers.index')
                ->with('error', "Could not fetch balance for [{$provider->name}]. Check the API URL and key.");

        } catch (\Exception $e) {
            return redirect()->route('admin.providers.index')
                ->with('error', "Error refreshing [{$provider->name}]: " . $e->getMessage());
        }
    }

    // ─── Refresh all balances ─────────────────────────────────────────────────

    public function refreshAllBalances()
    {
        $results = $this->providerService->refreshAllBalances();
        $count   = count(array_filter($results, fn($b) => $b !== null));

        return redirect()->route('admin.providers.index')
            ->with('success', "Balances refreshed for {$count}/" . count($results) . " provider(s).");
    }
}