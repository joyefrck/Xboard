<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_order_refund_order', function (Blueprint $table) {
            $table->integer('amount')->nullable();
            $table->integer('order_amount')->nullable();
            $table->integer('balance_payment')->nullable();
            $table->string('trade_no', 36)->nullable();
            $table->integer('order_created_at')->nullable();
        });
        Schema::create('v2_order_refund_mail', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('refund_id')->unique();
            $table->json('params');
            $table->integer('attempts')->default(0);
            $table->integer('queued_at')->nullable();
            $table->integer('next_attempt_at')->nullable()->index();
            $table->integer('sent_at')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_refund_mail');
        Schema::table('v2_order_refund_order', fn (Blueprint $table) => $table->dropColumn([
            'amount', 'order_amount', 'balance_payment', 'trade_no', 'order_created_at',
        ]));
    }
};
