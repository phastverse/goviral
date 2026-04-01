<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Traits\HasUuid;
use App\Notifications\ResetPasswordNotification;


class User extends Authenticatable
{
    use HasFactory, Notifiable, HasUuid;

    protected $fillable = [
        'name',
        'email',
        'status',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function wallet()
    {
        return $this->hasMany(Wallet::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function tickets()
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Get the user's referral (as a referrer)
     */
    public function referral()
    {
        return $this->hasOne(Referral::class);
    }

    /**
     * Get users this user has referred
     */
    public function referredUsers()
    {
        return $this->hasMany(ReferredUser::class, 'referrer_id');
    }

    /**
     * Get the referrer who referred this user
     */
    public function referredBy()
    {
        return $this->hasOne(ReferredUser::class, 'referred_user_id');
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function resellerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Reseller::class);
    }

    public function resellerMembership(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\ResellerUser::class);
    }

    public function belongsToReseller(): ?\App\Models\Reseller
    {
        return $this->resellerMembership?->reseller;
    }

}