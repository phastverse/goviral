<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('api_url');
            $table->string('api_key');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(1);
            $table->decimal('cached_balance', 12, 2)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('provider_id')->nullable()->constrained('providers')->nullOnDelete()->after('api_response');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
        });

        Schema::dropIfExists('providers');
    }
};