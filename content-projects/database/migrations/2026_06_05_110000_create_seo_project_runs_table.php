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
        Schema::connection($this->connection)->create('seo_project_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('seo_projects')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('mode', 20)->default('full')->comment('full, test');
            $table->string('status', 30)->default('completed')->comment('running, completed, failed');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('items')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_runs');
    }
};
