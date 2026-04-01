<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BoosterService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicApiController extends Controller
{
    protected $boosterService;

    public function __construct(BoosterService $boosterService)
    {
        $this->boosterService = $boosterService;
    }

    public function handle(Request $request)
    {
        $action = $request->input('action');

        // Services doesn't need auth
        if ($action === 'services') {
            return $this->services($request);
        }

        return match($action) {
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

    /**
     * GET services list
     */
    public function services(Request $request)
    {
        $services = $this->boosterService->getServices();

        if (empty($services)) {
            return response()->json(['error' => 'Unable to fetch services'], 500);
        }

        // Clean response — never expose upstream provider
        $cleaned = collect($services)->map(function ($service) {
            return [
                'service'  => $service['service'],
                'name'     => $this->cleanName($service['name']),
                'type'     => $service['type'] ?? 'Default',
                'category' => $this->cleanName($service['category'] ?? ''),
                'rate'     => $service['marked_up_price'] ?? $service['rate'],
                'min'      => $service['min'],
                'max'      => $service['max'],
            ];
        })->values();

        return response()->json($cleaned);
    }

    /**
     * POST add order
     */
    public function addOrder(Request $request)
    {
        $user = $this->apiUser($request);

        $request->validate([
            'service'  => 'required|integer',
            'link'     => 'required|url',
            'quantity' => 'required|integer|min:10',
        ]);

        // Fetch services and find the requested one
        $services = $this->boosterService->getServices();
        $serviceData = collect($services)->firstWhere('service', (int) $request->service);

        if (!$serviceData) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        // Validate quantity limits
        if ($request->quantity < $serviceData['min'] || $request->quantity > $serviceData['max']) {
            return response()->json([
                'error' => "Quantity must be between {$serviceData['min']} and {$serviceData['max']}"
            ], 422);
        }

        // Calculate charge using marked up price
        $rate   = $serviceData['marked_up_price'] ?? $serviceData['rate'];
        $charge = round(($request->quantity / 1000) * $rate, 2);

        // Check balance
        if ($user->balance < $charge) {
            return response()->json(['error' => 'Insufficient balance'], 402);
        }

        $reference = 'API-ORD-' . strtoupper(Str::random(8));

        // Deduct wallet
        WalletService::withdraw(
            $user,
            $charge,
            $reference,
            'API Order Debit',
            "API Order for: " . $this->cleanName($serviceData['name'])
        );

        // Place order with upstream
        $apiResponse = $this->boosterService->placeOrder(
            $request->service,
            $request->link,
            $request->quantity
        );

        if (isset($apiResponse['order']) && is_numeric($apiResponse['order'])) {
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
            ]);

            return response()->json(['order' => $order->id]);
        }

        // Refund on failure
        WalletService::refund($user, $charge, 'API Order Failed', $reference);

        return response()->json([
            'error' => $apiResponse['error'] ?? 'Order failed. Your balance has been refunded.'
        ], 500);
    }

    /**
     * GET order status
     */
    public function orderStatus(Request $request)
    {
        $user  = $this->apiUser($request);
        $order = Order::where('id', $request->input('order'))
                      ->where('user_id', $user->id)
                      ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Fetch live status from upstream
        if ($order->api_order_id) {
            $status = $this->boosterService->getOrderStatus($order->api_order_id);
            if (isset($status['status'])) {
                $order->update(['status' => $this->mapStatus($status['status'])]);
            }
        }

        return response()->json([
            'order'    => $order->id,
            'status'   => $order->status,
            'charge'   => $order->charge,
            'start_count' => $status['start_count'] ?? null,
            'remains'  => $status['remains'] ?? null,
        ]);
    }

    /**
     * GET multiple order statuses
     */
    public function multipleOrderStatus(Request $request)
    {
        $user     = $this->apiUser($request);
        $orderIds = explode(',', $request->input('orders', ''));

        $orders = Order::whereIn('id', $orderIds)
                       ->where('user_id', $user->id)
                       ->get();

        $result = [];
        foreach ($orders as $order) {
            $result[$order->id] = [
                'status'  => $order->status,
                'charge'  => $order->charge,
                'remains' => null,
            ];
        }

        return response()->json($result);
    }

    /**
     * POST create refill
     */
    public function createRefill(Request $request)
    {
        $user  = $this->apiUser($request);
        $order = Order::where('id', $request->input('order'))
                      ->where('user_id', $user->id)
                      ->first();

        if (!$order || !$order->api_order_id) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $response = $this->boosterService->createRefill($order->api_order_id);

        if (isset($response['refill'])) {
            return response()->json(['refill' => $response['refill']]);
        }

        return response()->json(['error' => $response['error'] ?? 'Refill failed'], 500);
    }

    /**
     * GET refill status
     */
    public function refillStatus(Request $request)
    {
        $refillId = $request->input('refill');
        $response = $this->boosterService->getRefillStatus($refillId);

        return response()->json([
            'refill' => $refillId,
            'status' => $response['status'] ?? 'unknown',
        ]);
    }

    /**
     * POST cancel orders
     */
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

        $apiOrderIds = $orders->pluck('api_order_id')->filter()->values()->toArray();

        $response = $this->boosterService->cancelOrders($apiOrderIds);

        return response()->json($response);
    }

    /**
     * GET wallet balance
     */
    public function balance(Request $request)
    {
        $user = $this->apiUser($request);

        return response()->json([
            'balance'  => number_format($user->balance, 2, '.', ''),
            'currency' => 'NGN',
        ]);
    }

    protected function cleanName($name): string
    {
        $cleaned = preg_replace('/\bogaviral\b/i', 'Booster', $name);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        return $cleaned;
    }

    protected function mapStatus($apiStatus): string
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