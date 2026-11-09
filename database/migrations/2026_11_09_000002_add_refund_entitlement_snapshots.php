<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_order_refund', function (Blueprint $table) {
            $table->json('snapshot')->nullable();
        });
        Schema::create('v2_order_refund_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('refund_id')->index();
            $table->integer('order_id')->unique();
        });
        // Preserve the audit lookup for records made before this migration.
        DB::table('v2_order_refund')->orderBy('id')->chunkById(500, function ($refunds) {
            foreach ($refunds as $refund) {
                DB::table('v2_order_refund_order')->insert(['refund_id' => $refund->id, 'order_id' => $refund->order_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_refund_order');
        Schema::table('v2_order_refund', fn (Blueprint $table) => $table->dropColumn('snapshot'));
    }
};
