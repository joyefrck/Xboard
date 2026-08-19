<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->integer('level')->nullable()->change();
        });

        $withdrawalSubjects = [
            '[Commission Withdrawal Request] This ticket is opened by the system',
            '[提现申请] 本工单由系统发出',
            '[提現申請] 本工單由系統發出'
        ];

        DB::table('v2_ticket')->update(['level' => null]);
        DB::table('v2_ticket')
            ->whereIn('subject', $withdrawalSubjects)
            ->update(['level' => 3]);
    }

    public function down(): void
    {
        DB::table('v2_ticket')
            ->where('level', 3)
            ->update(['level' => 2]);
        DB::table('v2_ticket')
            ->whereNull('level')
            ->update(['level' => 0]);

        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->integer('level')->nullable(false)->change();
        });
    }
};
