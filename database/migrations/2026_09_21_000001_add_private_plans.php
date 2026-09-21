<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->string('plan_type', 16)->default('standard')->index();
            $table->unsignedInteger('owner_user_id')->nullable()->index();
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedInteger('custom_pending_order_id')->nullable();
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->boolean('no_proration')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn('no_proration'));
        Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('custom_pending_order_id'));
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropIndex(['plan_type']);
            $table->dropIndex(['owner_user_id']);
            $table->dropColumn(['plan_type', 'owner_user_id']);
        });
    }
};
