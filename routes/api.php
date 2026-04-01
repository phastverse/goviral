<?php
use App\Http\Controllers\Api\PublicApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->post('/v2', [PublicApiController::class, 'handle']);