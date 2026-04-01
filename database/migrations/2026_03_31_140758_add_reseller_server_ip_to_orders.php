<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('server_ip')->nullable()->after('custom_domain');
            $table->timestamp('approved_at')->nullable()->after('server_ip');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });
    }

    public function down()
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['server_ip', 'approved_at', 'rejection_reason']);
        });
    }
};