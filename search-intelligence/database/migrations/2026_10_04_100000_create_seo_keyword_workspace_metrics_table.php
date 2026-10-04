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
        Schema::connection($this->connection)->create('seo_keyword_workspace_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('language_code', 32)->default('*');
            $table->string('namespace', 32);
            $table->string('metric_key', 64);
            $table->bigInteger('value')->default(0);
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['site_id', 'language_code', 'namespace', 'metric_key'],
                'seo_keyword_workspace_metrics_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_keyword_workspace_metrics');
    }
};
