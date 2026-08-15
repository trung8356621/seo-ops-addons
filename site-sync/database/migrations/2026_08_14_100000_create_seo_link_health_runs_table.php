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
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_link_health_runs')) {
            return;
        }

        $schema->create('seo_link_health_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('posts_processed')->default(0);
            $table->unsignedInteger('links_checked')->default(0);
            $table->unsignedInteger('broken_candidates')->default(0);
            $table->unsignedInteger('total_posts')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_link_health_runs');
    }
};
