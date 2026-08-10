<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * LEGACY no-op — automation schema owned by core migration.
 * @see database/migrations/2026_07_23_140000_create_core_automation_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // no-op
    }

    public function down(): void
    {
        // no-op
    }
};
