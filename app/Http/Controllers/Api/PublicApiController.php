<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ProviderService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicApiController extends Controller
{
    protected ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    public function handle(Request $request)
    {
        $action = $request->input('action');

        if ($action === 'services') return $this->services($request);

        return match ($action) {
            'add'           => $this->addOrder($request),
            'status'        => $request->has('orders')
                                ? $this->multipleOrderStatus($request)
                                : $this->orderStatus($request),
            'refill'        => $this->createRefill($request),
            'refill_status' => $this->refillStatus($request),
            'cancel'        => $this->cancelOrders($request),
            'balance'       => $this->balance($request),
            default         => response()->json(['error' => 'Invalid action'], 400),
        };
    }

    protected function apiUser(Request $request)
    {
        return $request->attributes->get('api_user');
    }

    public function services(Request $request)
    {
        $services = $this->providerService->getServices();

        if (empty($services)) {
            return response()->json(['error' => 'Unable to fetch services'], 500);
        }

        $cleaned = collect($services)->map(fn($service) => [
            'service'  => $service['service'],
            'name'     => $this->cleanName($service['name']),
            'type'     => $service['type'] ?? 'Default',
            'category' => $this->cleanName($service['category'] ?? ''),
            'rate'     => $service['marked_up_price'] ?? $service['rate'],
            'min'      => $service['min'],
            'max'      => $service['max'],
        ])->values();

        return response()->json($cleaned);
    }

    public function addOrder(Request $request)
    {
        $user = $this->apiUser($request);

        $request->validate([
            'service'  => 'required|integer',
            'link'     => 'required|url',
            'quantity' => 'required|integer|min:10',
        ]);

        $services    = $this->providerService->getServices();
        $serviceData = collect($services)->firstWhere('service', (int) $request->service);

        if (!$serviceData) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        if ($request->quantity < $serviceData['min'] || $request->quantity > $serviceData['max']) {
            return response()->json([
                'error' => "Quantity must be between {$serviceData['min']} and {$serviceData['max']}",
            ], 422);
        }

        $rate   = $serviceData['marked_up_price'] ?? $serviceData['rate'];
        $charge = round(($request->quantity / 1000) * $rate, 2);

        if ($user->balance < $charge) {
            return response()->json(['error' => 'Insufficient balance'], 402);
        }

        $reference = 'API-ORD-' . strtoupper(Str::random(8));

        WalletService::withdraw($user, $charge, $reference, 'API Order Debit',
            "API Order for: " . $this->cleanName($serviceData['name']));

        // Place via ProviderService (auto-fallback across all active providers)
        $result = $this->providerService->placeOrder(
            $request->service, $request->link, $request->quantity
        );

        if ($result !== null) {
            $apiResponse    = $result['response'];
            $chosenProvider = $result['provider'];

            $order = Order::create([
                'user_id'      => $user->id,
                'service_id'   => $request->service,
                'service_name' => $this->cleanName($serviceData['name']),
                'link'         => $request->link,
                'quantity'     => $request->quantity,
                'charge'       => $charge,
                'status'       => 'processing',
                'api_order_id' => $apiResponse['order'],
                'api_response' => json_encode($apiResponse),
                'provider_id'  => $chosenProvider->id,  // ← track provider
            ]);

            return response()->json(['order' => $order->id]);
        }

        // All providers failed — refund
        WalletService::refund($user, $charge, 'API Order Failed', $reference);

        return response()->json([
            'error' => 'Order failed. Your balance has been refunded.',
        ], 500);
    }

    public function orderStatus(Request $request)
    {
        $user  = $this->apiUser($request);
        $order = Order::where('id', $request->input('order'))
                      ->where('user_id', $user->id)
                      ->first();

        if (!$order) return response()->json(['error' => 'Order not found'], 404);

        $status = [];
        if ($order->api_order_id) {
            $status = $this->providerService->getOrderStatus($order->api_order_id, $order->provider_id);
            if (isset($status['status'])) {
                $order->update(['status' => $this->mapStatus($status['status'])]);
            }
        }

        return response()->json([
            'order'       => $order->id,
            'status'      => $order->status,
            'charge'      => $order->charge,
            'start_count' => $status['start_count'] ?? null,
            'remains'     => $status['remains'] ?? null,
        ]);
    }

    public function multipleOrderStatus(Request $request)
    {
        $user     = $this->apiUser($request);
        $orderIds = explode(',', $request->input('orders', ''));

        $orders = Order::whereIn('id', $orderIds)->where('user_id', $user->id)->get();

        return response()->json(
            $orders->keyBy('id')->map(fn($o) => [
                'status'  => $o->status,
                'charge'  => $o->charge,
                'remains' => null,
            ])
        );
    }

    public function createRefill(Request $request)
    {
        $user  = $this->apiUser($request);
        $order = Order::where('id', $request->input('order'))
                      ->where('user_id', $user->id)
                      ->first();

        if (!$order || !$order->api_order_id) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $response = $this->providerService->createRefill($order->api_order_id, $order->provider_id);

        if (isset($response['refill'])) {
            return response()->json(['refill' => $response['refill']]);
        }

        return response()->json(['error' => $response['error'] ?? 'Refill failed'], 500);
    }

    public function refillStatus(Request $request)
    {
        // Refill status check needs a provider — try any active one
        $refillId = $request->input('refill');
        $provider = \App\Models\Provider::active()->byPriority()->first();

        if (!$provider) {
            return response()->json(['refill' => $refillId, 'status' => 'unknown']);
        }

        $api      = new \App\Services\ProviderApiService($provider);
        $response = $api->post(['action' => 'refill_status', 'refill' => $refillId]);

        return response()->json([
            'refill' => $refillId,
            'status' => $response['status'] ?? 'unknown',
        ]);
    }

    public function cancelOrders(Request $request)
    {
        $user     = $this->apiUser($request);
        $orderIds = explode(',', $request->input('orders', ''));

        $orders = Order::whereIn('id', $orderIds)
                       ->where('user_id', $user->id)
                       ->whereIn('status', ['pending'])
                       ->get();

        if ($orders->isEmpty()) {
            return response()->json(['error' => 'No cancellable orders found'], 404);
        }

        // Use the first order's provider if available, otherwise pick any
        $firstOrder = $orders->first();
        $provider   = $firstOrder->provider ?? \App\Models\Provider::active()->byPriority()->first();

        if (!$provider) {
            return response()->json(['error' => 'No provider available'], 503);
        }

        $api         = new \App\Services\ProviderApiService($provider);
        $apiOrderIds = $orders->pluck('api_order_id')->filter()->values()->toArray();
        $response    = $api->post(['action' => 'cancel', 'orders' => implode(',', $apiOrderIds)]);

        return response()->json($response ?? ['error' => 'Cancel failed']);
    }

    public function balance(Request $request)
    {
        $user = $this->apiUser($request);

        return response()->json([
            'balance'  => number_format($user->balance, 2, '.', ''),
            'currency' => 'NGN',
        ]);
    }

    protected function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/\bogaviral\b/i', 'Booster', $name)));
    }

    protected function mapStatus(string $apiStatus): string
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
}