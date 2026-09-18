<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_telegram_group_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('source', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_telegram_group_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->bigInteger('telegram_id');
            $table->bigInteger('chat_id');
            $table->string('link_hash', 64)->unique();
            $table->text('invite_link');
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('used_at')->nullable();
            $table->unsignedInteger('revoked_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_telegram_group_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->unsignedBigInteger('invitation_id')->nullable()->index();
            $table->bigInteger('telegram_id');
            $table->bigInteger('chat_id');
            $table->unsignedInteger('requested_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('next_attempt_at')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['status', 'next_attempt_at'], 'tg_group_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_group_requests');
        Schema::dropIfExists('v2_telegram_group_invitations');
        Schema::dropIfExists('v2_telegram_group_entitlements');
    }
};
