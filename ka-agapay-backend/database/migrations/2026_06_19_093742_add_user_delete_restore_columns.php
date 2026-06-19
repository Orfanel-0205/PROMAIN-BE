<?php
//database/migrations/2026_06_19_093742_add_user_delete_restore_columns.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }

            if (!Schema::hasColumn('users', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable();
            }

            if (!Schema::hasColumn('users', 'delete_reason')) {
                $table->text('delete_reason')->nullable();
            }

            if (!Schema::hasColumn('users', 'profile_picture')) {
                $table->string('profile_picture')->nullable();
            }

            if (!Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable();
            }

            if (!Schema::hasColumn('users', 'barangay')) {
                $table->string('barangay', 150)->nullable();
            }

            if (!Schema::hasColumn('users', 'birthday')) {
                $table->date('birthday')->nullable();
            }

            if (!Schema::hasColumn('users', 'sex')) {
                $table->string('sex', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'sex')) {
                $table->dropColumn('sex');
            }

            if (Schema::hasColumn('users', 'birthday')) {
                $table->dropColumn('birthday');
            }

            if (Schema::hasColumn('users', 'barangay')) {
                $table->dropColumn('barangay');
            }

            if (Schema::hasColumn('users', 'avatar')) {
                $table->dropColumn('avatar');
            }

            if (Schema::hasColumn('users', 'profile_picture')) {
                $table->dropColumn('profile_picture');
            }

            if (Schema::hasColumn('users', 'delete_reason')) {
                $table->dropColumn('delete_reason');
            }

            if (Schema::hasColumn('users', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }

            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};