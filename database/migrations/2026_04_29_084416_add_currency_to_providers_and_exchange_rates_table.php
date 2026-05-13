<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add currency column to providers
        Schema::table('providers', function (Blueprint $table) {
            // The currency this provider charges in. Defaults to USD (most SMM panels).
            $table->string('currency', 10)->default('USD')->after('api_key');
        });

        // 2. Exchange rate cache table — avoids hammering the API on every order
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 10);   // e.g. 'USD'
            $table->string('to_currency', 10);     // e.g. 'NGN'
            $table->decimal('rate', 20, 6);        // e.g. 1620.500000
            $table->string('source')->default('api'); // which API provided this
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};