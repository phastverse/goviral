<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 
        'service_id', 
        'service_name', 
        'link', 
        'quantity', 
        'charge', 
        'profit',
        'markup_percentage',
        'status', 
        'api_order_id', 
        'api_response',
        'reseller_id',
        'provider_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}