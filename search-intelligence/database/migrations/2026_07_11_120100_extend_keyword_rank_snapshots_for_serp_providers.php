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
        Schema::connection($this->connection)->table('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_id')->nullable()->after('provider')->index();
            $table->string('country', 8)->nullable()->after('language');
            $table->string('request_status', 64)->nullable()->after('metadata');
            $table->unsignedInteger('duration_ms')->nullable()->after('request_status');
            $table->text('error_message')->nullable()->after('duration_ms');

            $table->index(['site_id', 'provider', 'keyword_id', 'checked_at'], 'krs_site_provider_kw_checked');
        });

        Schema::connection($this->connection)->table('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('connection_id')->nullable()->after('provider');
            $table->string('country', 8)->nullable()->after('language');
            $table->string('run_type', 32)->default('batch')->after('status');
            $table->string('comparison_batch_id', 64)->nullable()->after('run_type')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->dropIndex('krs_site_provider_kw_checked');
            $table->dropColumn(['connection_id', 'country', 'request_status', 'duration_ms', 'error_message']);
        });

        Schema::connection($this->connection)->table('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->dropColumn(['connection_id', 'country', 'run_type', 'comparison_batch_id']);
        });
    }
};
