<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ResellerUser extends Model
{
    use HasUuid;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = ['reseller_id', 'user_id'];

    public function reseller()
    {
        return $this->belongsTo(Reseller::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}