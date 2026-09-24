<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creator-owned seeding link assignments (DB SoT for FLOW B).
 * Independent of Topic/feed. Seeder daily progress stays in browser localStorage.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seeding_link_assignments')) {
            return;
        }

        $schema->create('seeding_link_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 64)->index();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('title', 255)->nullable();
            $table->text('url');
            $table->unsignedInteger('target_per_day')->default(5);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['installation_id', 'owner_user_id', 'is_active'], 'seeding_link_assign_owner_active_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seeding_link_assignments');
    }
};
