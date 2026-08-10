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
        Schema::connection($this->connection)->create('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('provider')->default('dataforseo');
            $table->string('location')->nullable();
            $table->string('language')->nullable();
            $table->string('device')->nullable();
            $table->decimal('position', 8, 2)->nullable();
            $table->string('ranking_url', 2048)->nullable();
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedInteger('allintitle')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'keyword_id', 'checked_at']);
        });

        Schema::connection($this->connection)->create('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_keywords')->default(0);
            $table->unsignedInteger('processed_keywords')->default(0);
            $table->unsignedInteger('failed_keywords')->default(0);
            $table->string('provider')->default('dataforseo');
            $table->string('location')->nullable();
            $table->string('language')->nullable();
            $table->string('device')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_rank_check_runs');
        Schema::connection($this->connection)->dropIfExists('keyword_rank_snapshots');
    }
};
