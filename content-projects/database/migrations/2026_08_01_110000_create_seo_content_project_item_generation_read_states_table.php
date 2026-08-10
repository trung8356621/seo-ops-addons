<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('seo_content_project_item_generation_read_states')) {
            return;
        }

        Schema::connection($this->connection)->create('seo_content_project_item_generation_read_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('project_item_id');
            $table->timestamp('viewed_generation_completed_at');
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index('user_id', 'cp_item_gen_read_user_idx');
            $table->index('project_id', 'cp_item_gen_read_project_idx');
            $table->index('project_item_id', 'cp_item_gen_read_item_idx');
            $table->unique(
                ['user_id', 'project_item_id'],
                'cp_item_gen_read_user_item_uidx',
            );
            $table->index(
                ['user_id', 'project_id'],
                'cp_item_gen_read_user_project_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_content_project_item_generation_read_states');
    }
};
