<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_tickets') && Schema::hasColumn('queue_tickets', 'ticket_number')) {
            DB::statement('ALTER TABLE queue_tickets ALTER COLUMN ticket_number TYPE VARCHAR(50)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('queue_tickets') && Schema::hasColumn('queue_tickets', 'ticket_number')) {
            DB::statement('ALTER TABLE queue_tickets ALTER COLUMN ticket_number TYPE VARCHAR(20)');
        }
    }
};