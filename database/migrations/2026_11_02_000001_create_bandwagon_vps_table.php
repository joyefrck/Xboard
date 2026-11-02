<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_bandwagon_vps', function (Blueprint $table) {
            $table->id();
            $table->string('veid', 20)->unique();
            $table->text('api_key');
            $table->string('name', 80)->default('');
            $table->string('hostname')->default('');
            $table->string('vm_type', 16)->default('');
            $table->string('imported_ip', 45)->nullable();
            $table->json('info')->nullable();
            $table->json('live')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('live_synced_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->uuid('sync_token')->nullable();
            $table->string('last_error')->nullable();
            $table->string('live_error')->nullable();
            $table->timestamp('reset_requested_at')->nullable();
            $table->string('reset_state', 16)->default('none');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('v2_bandwagon_vps'); }
};
