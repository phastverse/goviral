<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use App\Models\ResellerServiceMarkup;
use App\Services\OgaviralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ResellerServiceController extends Controller
{
    protected OgaviralService $ogaviralService;

    public function __construct(OgaviralService $ogaviralService)
    {
        $this->ogaviralService = $ogaviralService;
    }

    private function getReseller()
    {
        $reseller = Reseller::where('user_id', Auth::id())->firstOrFail();
        
        // Check if reseller is active
        if ($reseller->status !== 'active') {
            abort(403, 'Your panel is not active yet. Please wait for approval.');
        }
        
        return $reseller;
    }

    // Per-service markup configuration
    public function services()
    {
        $reseller = $this->getReseller();
        $services = $this->ogaviralService->getServices();

        // Load existing overrides keyed by service_id
        $overrides = $reseller->serviceMarkups()
            ->get()
            ->keyBy('service_id');

        return view('reseller-panel.services', compact('reseller', 'services', 'overrides'));
    }

    public function updateServiceMarkup(Request $request)
    {
        $reseller = $this->getReseller();

        $request->validate([
            'markups'                => 'required|array',
            'markups.*.service_id'   => 'required|integer',
            'markups.*.markup'       => 'nullable|numeric|min:0|max:500',
            'markups.*.hidden'       => 'nullable|boolean',
        ]);

        $chunks = array_chunk($request->markups, 100);
        
        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($reseller, $chunk) {
                foreach ($chunk as $row) {
                    // Use updateOrCreate which handles UUID correctly
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

        return redirect()->route('reseller-panel.services')->with('alert', [
            'type' => 'success',
            'message' => 'Service markups updated successfully!',
            'total_services' => count($request->markups),
        ]);
    }
}