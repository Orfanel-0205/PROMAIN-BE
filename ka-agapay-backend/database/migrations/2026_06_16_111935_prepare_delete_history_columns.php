<?php
// database/migrations/2026_06_16_111935_prepare_delete_history_columns.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function addDeleteTracking(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'deleted_at')) {
                $table->softDeletes();
            }

            if (!Schema::hasColumn($tableName, 'deleted_by')) {
                $table->foreignId('deleted_by')
                    ->nullable()
                    ->constrained('users', 'user_id')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn($tableName, 'delete_reason')) {
                $table->string('delete_reason', 500)->nullable();
            }
        });
    }

    private function addArchiveTracking(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }

            if (!Schema::hasColumn($tableName, 'archived_by')) {
                $table->foreignId('archived_by')
                    ->nullable()
                    ->constrained('users', 'user_id')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn($tableName, 'archive_reason')) {
                $table->string('archive_reason', 500)->nullable();
            }
        });
    }

    public function up(): void
    {
        $this->addDeleteTracking('announcements');
        $this->addDeleteTracking('events');
        $this->addDeleteTracking('appointments');
        $this->addDeleteTracking('consultations');
        $this->addDeleteTracking('inventory_items');

        $this->addArchiveTracking('announcements');
        $this->addArchiveTracking('events');
        $this->addArchiveTracking('appointments');
        $this->addArchiveTracking('consultations');
        $this->addArchiveTracking('inventory_items');

        if (Schema::hasTable('prescriptions')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                if (!Schema::hasColumn('prescriptions', 'delete_reason')) {
                    $table->string('delete_reason', 500)->nullable();
                }

                if (!Schema::hasColumn('prescriptions', 'deleted_by')) {
                    $table->foreignId('deleted_by')
                        ->nullable()
                        ->constrained('users', 'user_id')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Keep this migration non-destructive for health records.
        // Do not remove audit/delete columns automatically.
    }
};