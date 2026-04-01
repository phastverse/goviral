<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reseller_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reseller_id')->constrained('resellers')->onDelete('cascade');

            // This IS a regular users row — we just tag which reseller they belong to
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');

            $table->timestamps();

            $table->unique(['reseller_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_users');
    }
};