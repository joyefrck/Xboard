<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_ticket_bot_action', function (Blueprint $table) {
            $table->integer('ticket_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('actor', 64);
            $table->string('action', 16);
            $table->text('result');
            $table->integer('created_at');
            $table->primary(['ticket_id', 'key_hash']);
            $table->foreign('ticket_id')->references('id')->on('v2_ticket')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_bot_action');
    }
};
