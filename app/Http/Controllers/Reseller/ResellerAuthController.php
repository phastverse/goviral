<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ResellerUser;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResellerAuthController extends Controller
{
    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    public function showLogin()
    {
        $reseller = $this->currentReseller();
        return view('reseller.auth.login', compact('reseller'));
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $reseller = $this->currentReseller();

        if (!Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            return back()->with('alert', [
                'type'    => 'error',
                'message' => 'Invalid credentials.',
            ]);
        }

        $user = Auth::user();

        $isMember = $user->id === $reseller->user_id
            || ResellerUser::where('reseller_id', $reseller->id)
                           ->where('user_id', $user->id)
                           ->exists();

        if (!$isMember) {
            Auth::logout();
            return back()->with('alert', [
                'type'    => 'error',
                'message' => 'You do not have an account on this panel.',
            ]);
        }

        return redirect('/dashboard');
    }

    public function showRegister()
    {
        $reseller = $this->currentReseller();
        return view('reseller.auth.register', compact('reseller'));
    }

    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $reseller = $this->currentReseller();

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'status'   => 'active',
        ]);

        ResellerUser::create([
            'reseller_id' => $reseller->id,
            'user_id'     => $user->id,
        ]);

        Auth::login($user);

        return redirect('/dashboard')->with('alert', [
            'type'    => 'success',
            'message' => 'Welcome to ' . $reseller->panel_name . '!',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}