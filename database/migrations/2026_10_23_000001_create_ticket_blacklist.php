<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_muted_until')->nullable()->index();
            $table->unsignedInteger('ticket_abuse_count')->default(0);
        });
        // Evidence survives routine ticket deletion; deliberately no cascading foreign keys.
        Schema::create('v2_user_blacklist_event', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('email');
            $table->string('action', 32);
            $table->string('source', 16);
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->text('subject')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('category', 32)->nullable();
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedBigInteger('muted_until')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_blacklist_event');
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(['ticket_muted_until']);
            $table->dropColumn(['ticket_muted_until', 'ticket_abuse_count']);
        });
    }
};
