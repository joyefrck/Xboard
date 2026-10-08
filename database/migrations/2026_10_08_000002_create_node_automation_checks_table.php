<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_automation_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->string('ip', 45);
            $t->string('status', 16);
            $t->json('nodes')->nullable();
            $t->string('message', 255)->nullable();
            $t->timestamp('checked_at');
            $t->timestamps();
            $t->unique(['group_id', 'ip']);
        });
    }

    public function down(): void { Schema::dropIfExists('v2_node_automation_checks'); }
};
