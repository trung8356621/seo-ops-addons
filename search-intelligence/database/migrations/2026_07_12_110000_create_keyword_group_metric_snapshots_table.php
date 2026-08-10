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
        Schema::connection($this->connection)->create('keyword_group_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rank_group_id')->index();
            $table->unsignedBigInteger('rank_group_item_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('metric_type', 32);
            $table->string('provider', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->unsignedBigInteger('value_int')->nullable();
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->unsignedBigInteger('run_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['rank_group_id', 'rank_group_item_id', 'metric_type', 'checked_at'], 'kgms_group_item_metric_checked');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_group_metric_snapshots');
    }
};
