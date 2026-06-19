<?php
//database/migrations/2026_06_19_081552_add_admin_profile_staff_registration_user_delete_and_staff_announcement_support.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
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

                if (!Schema::hasColumn('users', 'sex')) {
                    $table->string('sex', 20)->nullable();
                }

                if (!Schema::hasColumn('users', 'birthday')) {
                    $table->date('birthday')->nullable();
                }
            });
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                if (!Schema::hasColumn('announcements', 'audience')) {
                    $table->string('audience', 30)->default('residents');
                }

                if (!Schema::hasColumn('announcements', 'notify_staff')) {
                    $table->boolean('notify_staff')->default(false);
                }

                if (!Schema::hasColumn('announcements', 'staff_roles')) {
                    $table->jsonb('staff_roles')->nullable();
                }

                if (!Schema::hasColumn('announcements', 'staff_notified_at')) {
                    $table->timestamp('staff_notified_at')->nullable();
                }

                if (!Schema::hasColumn('announcements', 'staff_notified_by')) {
                    $table->unsignedBigInteger('staff_notified_by')->nullable();
                }

                if (!Schema::hasColumn('announcements', 'staff_notifications_count')) {
                    $table->unsignedInteger('staff_notifications_count')->default(0);
                }
            });
        }

        if (!Schema::hasTable('staff_announcement_notifications')) {
            Schema::create('staff_announcement_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 50)->nullable();
                $table->string('title');
                $table->text('message')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at']);
                $table->index('announcement_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staff_announcement_notifications')) {
            Schema::dropIfExists('staff_announcement_notifications');
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                foreach ([
                    'audience',
                    'notify_staff',
                    'staff_roles',
                    'staff_notified_at',
                    'staff_notified_by',
                    'staff_notifications_count',
                ] as $column) {
                    if (Schema::hasColumn('announcements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach ([
                    'deleted_by',
                    'delete_reason',
                    'profile_picture',
                    'avatar',
                    'barangay',
                    'sex',
                    'birthday',
                ] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (Schema::hasColumn('users', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};