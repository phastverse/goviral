<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate'       => 'float',
        'fetched_at' => 'datetime',
    ];

    /**
     * Whether this cached rate is stale.
     * We refresh every 30 minutes by default.
     */
    public function isStale(int $ttlMinutes = 30): bool
    {
        if (!$this->fetched_at) return true;
        return $this->fetched_at->lt(now()->subMinutes($ttlMinutes));
    }
}