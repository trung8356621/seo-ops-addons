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
        if ($schema->hasTable('seo_link_analysis_runs')) {
            return;
        }

        $schema->create('seo_link_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('status', 32)->index();
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('processed_posts')->default(0);
            $table->unsignedInteger('total_posts')->nullable();
            $table->unsignedInteger('opportunities')->default(0);
            $table->unsignedInteger('orphan_pages')->default(0);
            $table->unsignedInteger('internal_links')->default(0);
            $table->json('summary')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_link_analysis_runs');
    }
};
