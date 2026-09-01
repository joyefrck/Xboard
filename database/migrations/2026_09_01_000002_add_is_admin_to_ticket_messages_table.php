<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('user_id');
        });

        DB::table('v2_ticket_message')
            ->join('v2_ticket', 'v2_ticket.id', '=', 'v2_ticket_message.ticket_id')
            ->whereColumn('v2_ticket_message.user_id', '!=', 'v2_ticket.user_id')
            ->select('v2_ticket_message.id')
            ->orderBy('v2_ticket_message.id')
            ->chunkById(500, function ($messages) {
                DB::table('v2_ticket_message')
                    ->whereIn('id', $messages->pluck('id'))
                    ->update(['is_admin' => true]);
            }, 'v2_ticket_message.id', 'id');
    }

    public function down(): void
    {
        Schema::table('v2_ticket_message', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
