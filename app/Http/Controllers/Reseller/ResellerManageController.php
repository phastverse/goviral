<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerServiceMarkup;
use App\Services\OgaviralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResellerManageController extends Controller
{
    protected OgaviralService $ogaviralService;

    public function __construct(OgaviralService $ogaviralService)
    {
        $this->ogaviralService = $ogaviralService;
    }

    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    private function ensureOwner(): void
    {
        $reseller = $this->currentReseller();
        if (Auth::id() !== $reseller->user_id) {
            abort(403, 'Only the panel owner can access this page.');
        }
    }

    // Panel settings (branding, default markup)
    public function settings()
    {
        $this->ensureOwner();
        $reseller = $this->currentReseller();
        return view('reseller.manage.settings', compact('reseller'));
    }

    public function updateSettings(Request $request)
    {
        $this->ensureOwner();
        $reseller = $this->currentReseller();

        $request->validate([
            'panel_name'             => 'required|string|max:100',
            'primary_color'          => 'required|string|max:7',
            'support_email'          => 'nullable|email',
            'default_markup_percent' => 'required|numeric|min:0|max:200',
        ]);

        $reseller->update($request->only([
            'panel_name',
            'primary_color',
            'support_email',
            'default_markup_percent',
        ]));

        return back()->with('alert', [
            'type'    => 'success',
            'message' => 'Panel settings updated.',
        ]);
    }

    // Per-service markup configuration
    public function services()
    {
        $this->ensureOwner();
        $reseller  = $this->currentReseller();
        $services  = $this->ogaviralService->getServices();

        // Load existing overrides keyed by service_id
        $overrides = $reseller->serviceMarkups()
            ->get()
            ->keyBy('service_id');

        return view('reseller.manage.services', compact('reseller', 'services', 'overrides'));
    }

    public function updateServiceMarkup(Request $request)
    {
        $this->ensureOwner();
        $reseller = $this->currentReseller();

        $request->validate([
            'markups'                => 'required|array',
            'markups.*.service_id'   => 'required|integer',
            'markups.*.markup'       => 'nullable|numeric|min:0|max:500',
            'markups.*.hidden'       => 'nullable|boolean',
        ]);

        // Use chunking for better performance with UUID
        $chunks = array_chunk($request->markups, 100);
        
        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($reseller, $chunk) {
                foreach ($chunk as $row) {
                    ResellerServiceMarkup::updateOrCreate(
                        [
                            'reseller_id' => $reseller->id,
                            'service_id'  => $row['service_id'],
                        ],
                        [
                            'markup_percent' => $row['markup'] ?? $reseller->default_markup_percent,
                            'is_hidden'      => isset($row['hidden']) && $row['hidden'],
                        ]
                    );
                }
            });
        }

        return back()->with('alert', [
            'type'    => 'success',
            'message' => 'Service markups updated successfully.',
            'total_services' => count($request->markups),
        ]);
    }

    // Customers list
    public function customers()
    {
        $this->ensureOwner();
        $reseller  = $this->currentReseller();
        $customers = $reseller->customers()->paginate(30);

        return view('reseller.manage.customers', compact('reseller', 'customers'));
    }

    // Revenue summary for the owner
    public function revenue()
    {
        $this->ensureOwner();
        $reseller = $this->currentReseller();

        $totalRevenue   = $reseller->orders()->sum('charge');
        $totalProfit    = $reseller->orders()->where('status', 'completed')->sum('profit');
        $totalOrders    = $reseller->orders()->count();
        $completedOrders = $reseller->orders()->where('status', 'completed')->count();
        $pendingOrders = $reseller->orders()->where('status', 'pending')->count();
        $processingOrders = $reseller->orders()->where('status', 'processing')->count();
        $cancelledOrders = $reseller->orders()->where('status', 'cancelled')->count();
        $recentOrders   = $reseller->orders()->with('user')->latest()->paginate(30);

        return view('reseller.manage.revenue', compact(
            'reseller', 'totalRevenue', 'totalProfit', 'totalOrders',
            'completedOrders', 'pendingOrders', 'processingOrders', 'cancelledOrders',
            'recentOrders'
        ));
    }
}