<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningDataBackfillService;

/**
 * Repair path for environments that already ran the original 100300 (5k-capped) backfill.
 * Idempotent: only fills missing planning_month / tombstones / unattributed attributions.
 * Does not overwrite attributed snapshots or already-stamped planning_month values.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PlanningDataBackfillService)->runAll();
    }

    public function down(): void
    {
        // Non-destructive repair; no rollback of historical stamps.
    }
};
