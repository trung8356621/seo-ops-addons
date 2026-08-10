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
        if (Schema::connection($this->connection)->hasTable('seo_content_project_agent_sessions')) {
            return;
        }

        Schema::connection($this->connection)->create('seo_content_project_agent_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_ref', 64)->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('actor_ref', 128);
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_content_project_agent_sessions');
    }
};
