<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptDefaults;

/**
 * Simple Manager Gen Comment prompt + ring-buffer debug history (max 20).
 * Not SEO prompt versioning — single editable body + latest execution snapshots.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seeding_comment_prompt_settings')) {
            $schema->create('seeding_comment_prompt_settings', function (Blueprint $table): void {
                $table->id();
                $table->longText('prompt_body');
                $table->timestamps();
            });
        }

        if (DB::connection($this->connection)->table('seeding_comment_prompt_settings')->count() === 0) {
            $now = now();
            DB::connection($this->connection)->table('seeding_comment_prompt_settings')->insert([
                'prompt_body' => SeedingCommentPromptDefaults::promptBody(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! $schema->hasTable('seeding_comment_generate_log_meta')) {
            $schema->create('seeding_comment_generate_log_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('next_sequence')->default(0);
            });
        }

        if (DB::connection($this->connection)->table('seeding_comment_generate_log_meta')->count() === 0) {
            DB::connection($this->connection)->table('seeding_comment_generate_log_meta')->insert([
                'next_sequence' => 0,
            ]);
        }

        if (! $schema->hasTable('seeding_comment_generate_logs')) {
            $schema->create('seeding_comment_generate_logs', function (Blueprint $table): void {
                $table->unsignedTinyInteger('slot')->primary();
                $table->unsignedBigInteger('sequence')->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->string('social', 64)->nullable();
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->longText('mcp_context');
                $table->longText('final_prompt');
                $table->longText('ai_output')->nullable();
                $table->string('provider', 128)->nullable();
                $table->string('model', 191)->nullable();
                $table->string('status', 32);
                $table->text('error_message')->nullable();
                $table->timestamp('generated_at')->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seeding_comment_generate_logs');
        $schema->dropIfExists('seeding_comment_generate_log_meta');
        $schema->dropIfExists('seeding_comment_prompt_settings');
    }
};
