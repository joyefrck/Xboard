<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_ticket_message_attachment', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('ticket_message_id')->index();
            $table->string('original_name', 255);
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_message_attachment');
    }
};
