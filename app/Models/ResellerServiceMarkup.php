<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ResellerServiceMarkup extends Model
{
    use HasUuid;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'reseller_id',
        'service_id',
        'markup_percent',
        'is_hidden',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    public function reseller()
    {
        return $this->belongsTo(Reseller::class);
    }
}