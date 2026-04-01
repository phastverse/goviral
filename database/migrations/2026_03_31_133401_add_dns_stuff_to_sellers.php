<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->enum('custom_domain_status', ['pending', 'active', 'failed'])->default('pending')->after('custom_domain');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain_status');
            $table->text('custom_domain_error')->nullable()->after('custom_domain_verified_at');
        });
    }

    public function down()
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['custom_domain_status', 'custom_domain_verified_at', 'custom_domain_error']);
        });
    }
};