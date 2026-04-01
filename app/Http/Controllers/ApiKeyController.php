<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index()
    {
        $apiKeys = auth()->user()->apiKeys()->latest()->get();
        return view('api.index', compact('apiKeys'));
    }

    public function generate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:50',
        ]);

        $user = auth()->user();

        // Limit to 3 keys per user
        if ($user->apiKeys()->count() >= 3) {
            return redirect()->back()->with('alert', [
                'type' => 'error',
                'message' => 'You can only have up to 3 API keys.'
            ]);
        }

        ApiKey::create([
            'user_id' => $user->id,
            'key'     => 'bst_' . Str::random(32),
            'name'    => $request->name,
            'status'  => 'active',
        ]);

        return redirect()->back()->with('alert', [
            'type' => 'success',
            'message' => 'API key generated successfully!'
        ]);
    }

    public function toggle(ApiKey $apiKey)
    {
        if ($apiKey->user_id !== auth()->id()) abort(403);

        $apiKey->update([
            'status' => $apiKey->status === 'active' ? 'inactive' : 'active'
        ]);

        return redirect()->back()->with('alert', [
            'type' => 'success',
            'message' => 'API key status updated.'
        ]);
    }

    public function destroy(ApiKey $apiKey)
    {
        if ($apiKey->user_id !== auth()->id()) abort(403);

        $apiKey->delete();

        return redirect()->back()->with('alert', [
            'type' => 'success',
            'message' => 'API key deleted.'
        ]);
    }
}