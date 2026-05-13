<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokEventService
{
    protected $pixelCode;
    protected $accessToken;
    protected $isEnabled;

    public function __construct()
    {
        $this->pixelCode = config('services.tiktok.pixel_code');
        $this->accessToken = config('services.tiktok.access_token');
        $this->isEnabled = config('services.tiktok.enabled', false);
        
        // Add debug logging
        Log::info('TikTok Service initialized', [
            'pixel_exists' => !empty($this->pixelCode),
            'token_exists' => !empty($this->accessToken),
            'token_length' => strlen($this->accessToken ?? ''),
            'enabled' => $this->isEnabled
        ]);
    }

    /**
     * Send purchase event to TikTok
     */
    public function sendPurchaseEvent($order, $user, $request)
    {
        // Check configuration
        if (!$this->isEnabled) {
            Log::warning('TikTok events disabled');
            return false;
        }
        
        if (empty($this->pixelCode)) {
            Log::error('TikTok pixel code is empty');
            return false;
        }
        
        if (empty($this->accessToken)) {
            Log::error('TikTok access token is empty - check your .env file and run php artisan config:clear');
            return false;
        }

        // Get customer IP from request
        $customerIp = $request->ip();
        
        // Get user agent from request
        $userAgent = $request->userAgent();

        // Get TikTok cookie from browser (if available)
        $tiktokCookie = $request->cookie('_ttp') ?? null;

        $eventData = [
            'event' => 'Purchase',
            'event_id' => 'purchase_' . $order->id . '_' . time(),
            'event_source' => 'web',
            'timestamp' => now()->timestamp,
            'user' => [
                'ip' => $customerIp,
                'user_agent' => $userAgent,
                'ttp' => $tiktokCookie,
            ],
            'properties' => [
                'contents' => [
                    [
                        'content_id' => (string) $order->service_id,
                        'content_name' => $order->service_name,
                        'content_type' => 'product',
                        'price' => (float) $order->charge,
                        'quantity' => (int) $order->quantity,
                    ]
                ],
                'content_type' => 'product',
                'currency' => 'NGN',
                'value' => (float) $order->charge,
                'num_items' => 1,
                'order_id' => (string) $order->id,
                'status' => $order->status,
                'description' => "Order for {$order->service_name}",
                'content_category' => $this->getServiceCategory($order->service_name),
            ]
        ];

        // Add email and phone if available
        if ($user && $user->email) {
            $eventData['user']['email'] = md5(strtolower(trim($user->email)));
        }
        
        if ($user && $user->phone) {
            $eventData['user']['phone_number'] = md5(strtolower(trim($user->phone)));
        }

        Log::info('Sending to TikTok', [
            'pixel_code' => substr($this->pixelCode, 0, 10) . '...',
            'token_prefix' => substr($this->accessToken, 0, 20) . '...'
        ]);

        return $this->sendToTikTok($eventData);
    }

    /**
     * Send event to TikTok Events API
     */
    protected function sendToTikTok($eventData)
    {
        $url = "https://business-api.tiktok.com/open_api/v1.3/pixel/track/";

        $payload = [
            'pixel_code' => $this->pixelCode,
            'access_token' => $this->accessToken,
            'data' => [$eventData],
        ];

        try {
            $response = Http::timeout(5)
                ->retry(2, 100)
                ->post($url, $payload);

            if ($response->successful()) {
                $result = $response->json();
                if (isset($result['code']) && $result['code'] === 0) {
                    Log::info('TikTok purchase event sent successfully', [
                        'order_id' => $eventData['properties']['order_id'] ?? 'unknown'
                    ]);
                    return true;
                } else {
                    Log::error('TikTok event API error', [
                        'code' => $result['code'] ?? 'unknown',
                        'message' => $result['message'] ?? 'Unknown error',
                        'order_id' => $eventData['properties']['order_id'] ?? 'unknown'
                    ]);
                    return false;
                }
            } else {
                Log::error('TikTok event HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'order_id' => $eventData['properties']['order_id'] ?? 'unknown'
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('TikTok event exception: ' . $e->getMessage(), [
                'order_id' => $eventData['properties']['order_id'] ?? 'unknown'
            ]);
            return false;
        }
    }

    /**
     * Map service name to content category
     */
    protected function getServiceCategory($serviceName)
    {
        if (stripos($serviceName, 'Instagram') !== false) return 'social_media';
        if (stripos($serviceName, 'TikTok') !== false) return 'social_media';
        if (stripos($serviceName, 'Facebook') !== false) return 'social_media';
        if (stripos($serviceName, 'YouTube') !== false) return 'video';
        if (stripos($serviceName, 'Spotify') !== false) return 'music';
        if (stripos($serviceName, 'Telegram') !== false) return 'messaging';
        if (stripos($serviceName, 'Twitter') !== false) return 'social_media';
        if (stripos($serviceName, 'LinkedIn') !== false) return 'professional';
        return 'digital_service';
    }
}