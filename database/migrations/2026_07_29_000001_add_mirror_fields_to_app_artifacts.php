<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_app_artifacts')) {
            return;
        }

        Schema::table('v2_app_artifacts', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_app_artifacts', 'mirror_status')) {
                $table->string('mirror_status', 16)
                    ->default('local')
                    ->after('uploaded_by')
                    ->index('v2_app_artifacts_mirror_status_index');
            }

            if (!Schema::hasColumn('v2_app_artifacts', 'mirror_path')) {
                $table->string('mirror_path', 1024)->nullable();
            }

            if (!Schema::hasColumn('v2_app_artifacts', 'mirror_error')) {
                $table->text('mirror_error')->nullable();
            }

            if (!Schema::hasColumn('v2_app_artifacts', 'mirrored_at')) {
                $table->timestamp('mirrored_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_app_artifacts')) {
            return;
        }

        if (Schema::hasColumn('v2_app_artifacts', 'mirror_status')) {
            try {
                Schema::table('v2_app_artifacts', function (Blueprint $table) {
                    $table->dropIndex('v2_app_artifacts_mirror_status_index');
                });
            } catch (Throwable $e) {
                // A partially applied migration may not have created the index.
            }
        }

        $columns = array_values(array_filter([
            'mirror_status',
            'mirror_path',
            'mirror_error',
            'mirrored_at',
        ], fn (string $column): bool => Schema::hasColumn('v2_app_artifacts', $column)));

        if ($columns !== []) {
            Schema::table('v2_app_artifacts', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
