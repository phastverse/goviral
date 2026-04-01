<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ResellerProfileController extends Controller
{
    private function currentReseller(): \App\Models\Reseller
    {
        return app('current_reseller');
    }

    public function index()
    {
        $reseller = $this->currentReseller();
        $user     = Auth::user();

        return view('reseller.profile.index', compact('reseller', 'user'));
    }

    public function update(Request $request)
    {
        $user     = Auth::user();
        $reseller = $this->currentReseller();

        // Determine which form was submitted
        if ($request->has('update_profile')) {
            $this->updateProfile($request, $user);
        } elseif ($request->has('update_password')) {
            $this->updatePassword($request, $user);
        }

        return redirect()->route('reseller.profile.index');
    }

    private function updateProfile(Request $request, $user): void
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
        ]);

        $user->update([
            'name'  => $request->name,
            'email' => $request->email,
        ]);

        session()->flash('alert', [
            'type'    => 'success',
            'message' => 'Profile updated successfully.',
        ]);
    }

    private function updatePassword(Request $request, $user): void
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            session()->flash('alert', [
                'type'    => 'error',
                'message' => 'Current password is incorrect.',
            ]);
            return;
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        session()->flash('alert', [
            'type'    => 'success',
            'message' => 'Password changed successfully.',
        ]);
    }
}