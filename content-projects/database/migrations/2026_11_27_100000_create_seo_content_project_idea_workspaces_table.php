<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idea Manager durable workspace — one normalized note_items snapshot per site.
 * Planning payload only; does not create Topic Core rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->create('seo_content_project_idea_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->json('note_items');
            $table->unsignedBigInteger('revision')->default(1);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('site_id', 'scp_idea_workspaces_site_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_idea_workspaces');
    }
};
