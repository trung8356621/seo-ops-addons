<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared topics on omi_seeding — commit point when author presses Chia sẻ.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seeding_topics')) {
            return;
        }

        $schema->create('seeding_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 64)->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('created_by_display_name', 191)->nullable();
            $table->string('title', 255)->nullable();
            $table->longText('full_text');
            $table->longText('source_html')->nullable();
            $table->text('social_url')->nullable();
            $table->string('social_platform', 32)->nullable();
            $table->json('links_json')->nullable();
            $table->string('status', 16)->default('shared')->index();
            $table->unsignedInteger('max_comments_target')->default(20);
            $table->unsignedInteger('member_count_at_share')->default(1);
            $table->unsignedInteger('required_comments_per_user')->default(1);
            $table->timestamp('shared_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index(['installation_id', 'status', 'shared_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seeding_topics');
    }
};
