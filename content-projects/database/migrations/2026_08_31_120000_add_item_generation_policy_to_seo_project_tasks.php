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
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'tone_override')) {
                $table->string('tone_override', 100)->nullable();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'resolved_tone')) {
                $table->string('resolved_tone', 100)->nullable();
            }

            // short|standard|long|custom
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'content_length_override')) {
                $table->string('content_length_override', 32)->nullable();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'content_length_target_words')) {
                $table->unsignedInteger('content_length_target_words')->nullable();
            }

            // fast_economy|best_quality
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_mode_override')) {
                $table->string('generation_mode_override', 32)->nullable();
            }

            // Logical link to the AI model catalog — no FK across databases.
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'model_override_id')) {
                $table->unsignedBigInteger('model_override_id')->nullable();
            }

            // preferred|required
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'model_override_mode')) {
                $table->string('model_override_mode', 16)->nullable();
            }

            // user|generated|reviewed
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'title_protection')) {
                $table->string('title_protection', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        $columns = [
            'tone_override',
            'resolved_tone',
            'content_length_override',
            'content_length_target_words',
            'generation_mode_override',
            'model_override_id',
            'model_override_mode',
            'title_protection',
        ];

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table) use ($columns): void {
            foreach ($columns as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
