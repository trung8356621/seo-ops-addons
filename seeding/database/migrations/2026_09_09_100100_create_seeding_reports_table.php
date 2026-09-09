<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report commit point — proof + comment snapshot after external social post.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seeding_reports')) {
            return;
        }

        $schema->create('seeding_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('topic_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_display_name', 191)->nullable();
            $table->longText('comment_text');
            $table->string('seed_link_id', 64)->nullable()->index();
            $table->text('seed_url')->nullable();
            $table->string('proof_path', 512)->nullable();
            $table->string('proof_mime', 128)->nullable();
            $table->json('proof_meta')->nullable();
            $table->timestamp('reported_at')->index();
            $table->timestamps();

            $table->index(['topic_id', 'user_id']);
            $table->index(['user_id', 'seed_link_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seeding_reports');
    }
};
