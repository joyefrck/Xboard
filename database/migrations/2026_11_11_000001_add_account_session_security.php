<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('login_ip', 45)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('client_label', 80)->nullable();
            $table->string('client_type', 20)->nullable();
            $table->string('client_os', 40)->nullable();
            $table->timestamp('metadata_seen_at')->nullable();
        });
        Schema::create('v2_user_trusted_ips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('ip_hash', 64);
            $table->string('email_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->unique(['user_id', 'ip_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_trusted_ips');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['login_ip', 'last_ip', 'client_label', 'client_type', 'client_os', 'metadata_seen_at']);
        });
    }
};
