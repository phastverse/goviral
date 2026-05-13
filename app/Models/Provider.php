<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Provider extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'api_url', 
        'api_key',
        'is_active',
        'priority',
        'cached_balance',
        'balance_checked_at',
        'notes',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'cached_balance'     => 'float',
        'balance_checked_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the cached balance is stale (older than 10 minutes).
     */
    public function isBalanceStale(): bool
    {
        if (!$this->balance_checked_at) return true;
        return $this->balance_checked_at->lt(now()->subMinutes(10));
    }
}