<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_order_refund', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id')->unique();
            $table->string('trade_no', 36);
            $table->integer('user_id')->index();
            $table->integer('operator_id');
            $table->integer('amount');
            $table->integer('balance_before');
            $table->integer('balance_after');
            $table->string('reason', 500);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_refund');
    }
};
