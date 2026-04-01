<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The owner of this reseller panel — a regular user on your platform
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');

            // Subdomain: e.g. "acme" for acme.booster.com
            $table->string('subdomain')->unique();

            // Branding
            $table->string('panel_name');                  // e.g. "AcmeBoost"
            $table->string('logo_path')->nullable();
            $table->string('primary_color')->default('#6366f1');
            $table->string('support_email')->nullable();

            // Global markup that applies to all services unless overridden
            $table->decimal('default_markup_percent', 8, 2)->default(20.00);

            $table->enum('status', ['active', 'suspended', 'pending'])->default('pending');

            // Custom domain support (optional)
            $table->string('custom_domain')->nullable()->unique();

            $table->timestamps();
        });

        // Per-service markup overrides (reseller can price individual services differently)
        Schema::create('reseller_service_markups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reseller_id')->constrained('resellers')->onDelete('cascade');
            $table->unsignedBigInteger('service_id');      // Ogaviral service ID
            $table->decimal('markup_percent', 8, 2);
            $table->boolean('is_hidden')->default(false);  // reseller can hide services
            $table->timestamps();

            $table->unique(['reseller_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_service_markups');
        Schema::dropIfExists('resellers');
    }
};