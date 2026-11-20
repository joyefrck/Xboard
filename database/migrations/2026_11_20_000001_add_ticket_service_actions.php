<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedInteger('access_override_plan_id')->nullable();
            $table->unsignedInteger('access_override_until')->nullable()->index();
        });
        // Financial and entitlement evidence survives ticket retention.
        Schema::create('v2_ticket_service_action', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('ticket_id')->index();
            $table->integer('user_id');
            $table->string('actor', 64);
            $table->string('action', 16);
            $table->index(['user_id', 'action', 'id'], 'ticket_service_user_action');
            $table->string('reason', 500);
            $table->json('details');
            $table->integer('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_service_action');
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(['access_override_until']);
            $table->dropColumn(['access_override_plan_id', 'access_override_until']);
        });
    }
};
