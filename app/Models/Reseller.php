<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    use HasUuid;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'user_id',
        'subdomain',
        'panel_name',
        'logo_path',
        'primary_color',
        'support_email',
        'default_markup_percent',
        'status',
        'custom_domain',
        'server_ip',
        'rejection_reason',
    ];


    protected $casts = [
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // The platform user who owns this panel
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Per-service markup overrides
    public function serviceMarkups(): HasMany
    {
        return $this->hasMany(ResellerServiceMarkup::class);
    }

    // All end-customer rows linked to this reseller
    public function resellerUsers(): HasMany
    {
        return $this->hasMany(ResellerUser::class);
    }

    // The end-customer User models
    public function customers()
    {
        return $this->hasManyThrough(User::class, ResellerUser::class, 'reseller_id', 'id', 'id', 'user_id');
    }

    // Orders placed on this panel
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Calculate the price a customer on this panel pays for a service.
     * Falls back to the reseller's default markup if no specific override exists.
     */
    public function priceForService(float $baseRate, int $serviceId): float
    {
        $override = $this->serviceMarkups()
            ->where('service_id', $serviceId)
            ->first();

        $markupPercent = $override
            ? $override->markup_percent
            : $this->default_markup_percent;

        return round($baseRate * (1 + $markupPercent / 100), 4);
    }
}