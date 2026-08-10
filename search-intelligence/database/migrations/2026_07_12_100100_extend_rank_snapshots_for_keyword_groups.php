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
            $table->unsignedBigInteger('rank_group_id')->nullable()->after('site_id')->index();
            $table->unsignedBigInteger('rank_group_item_id')->nullable()->after('rank_group_id')->index();
        });

        Schema::connection($this->connection)->table('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('rank_group_id')->nullable()->after('site_id')->index();
            $table->string('connection_hash', 64)->nullable()->after('rank_group_id');
        });

        Schema::connection($this->connection)->table('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->change();
        });

        Schema::connection($this->connection)->table('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['rank_group_id', 'rank_group_item_id']);
        });

        Schema::connection($this->connection)->table('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->dropColumn(['rank_group_id', 'connection_hash']);
        });
    }
};
