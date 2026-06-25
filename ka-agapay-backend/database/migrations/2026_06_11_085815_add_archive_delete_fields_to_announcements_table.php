<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Duplicate placeholder kept non-destructive; the later migration with
        // the same purpose contains the actual announcements archive/delete logic.
    }

    public function down(): void
    {
        // No-op.
    }
};
