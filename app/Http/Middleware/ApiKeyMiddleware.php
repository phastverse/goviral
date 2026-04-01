<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->input('key');

        if (!$key) {
            return response()->json(['error' => 'API key is required'], 401);
        }

        $apiKey = ApiKey::where('key', $key)->where('status', 'active')->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or inactive API key'], 401);
        }

        // Update last used
        $apiKey->update(['last_used_at' => now()]);

        // Attach user to request
        $request->merge(['_api_user' => $apiKey->user]);
        $request->attributes->set('api_user', $apiKey->user);

        return $next($request);
    }
}