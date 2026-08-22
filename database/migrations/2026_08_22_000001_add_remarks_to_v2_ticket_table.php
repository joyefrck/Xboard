<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('v2_ticket', 'remarks')) {
            return;
        }

        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->text('remarks')->nullable()->after('reply_status')->comment('管理员备注');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('v2_ticket', 'remarks')) {
            return;
        }

        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
