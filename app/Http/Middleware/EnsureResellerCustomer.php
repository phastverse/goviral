<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureResellerCustomer
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $reseller = app()->bound('current_reseller') ? app('current_reseller') : null;

        if (!$reseller) {
            abort(404);
        }

        $user = Auth::user();

        // The reseller owner can also act as a customer on their own panel
        if ($user->id === $reseller->user_id) {
            return $next($request);
        }

        $isMember = $user->resellerMembership()->where('reseller_id', $reseller->id)->exists();

        if (!$isMember) {
            Auth::logout();
            return redirect()->route('login')->with('alert', [
                'type'    => 'error',
                'message' => 'Your account is not registered on this panel.',
            ]);
        }

        return $next($request);
    }
}