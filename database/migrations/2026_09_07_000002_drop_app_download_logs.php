<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('v2_app_download_logs');
    }

    public function down(): void
    {
        Schema::create('v2_app_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')
                ->constrained('v2_distribution_apps')
                ->cascadeOnDelete();
            $table->foreignId('app_version_id')
                ->constrained('v2_app_versions')
                ->cascadeOnDelete();
            $table->foreignId('app_artifact_id')
                ->nullable()
                ->constrained('v2_app_artifacts')
                ->cascadeOnDelete();
            $table->integer('user_id')->nullable()->index();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('downloaded_at')->index();
            $table->timestamps();
        });
    }
};
