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

        if (! $schema->hasTable('seo_site_health_incidents')) {
            $schema->create('seo_site_health_incidents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('status', 24)->index();
                $table->string('severity', 16)->index();
                $table->string('error_code', 64)->index();
                $table->text('reason')->nullable();
                $table->text('technical_error')->nullable();
                $table->json('diagnostic_stages')->nullable();
                $table->timestamp('detected_at');
                $table->timestamp('last_occurred_at');
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();
                $table->index(['site_id', 'resolved_at']);
            });
        }

        if (! $schema->hasTable('seo_site_health_states')) {
            $schema->create('seo_site_health_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->unique();
                $table->unsignedBigInteger('active_incident_id')->nullable()->index();
                $table->string('status', 24)->default('healthy')->index();
                $table->string('severity', 16)->nullable();
                $table->string('error_code', 64)->nullable();
                $table->text('reason')->nullable();
                $table->text('technical_error')->nullable();
                $table->json('diagnostic_stages')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('first_failure_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_site_health_states');
        $schema->dropIfExists('seo_site_health_incidents');
    }
};
