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
        if (Schema::connection($this->connection)->hasTable('wordpress_side_effect_attempts')) {
            return;
        }

        Schema::connection($this->connection)->create('wordpress_side_effect_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 64);
            $table->string('origin', 32);
            $table->string('correlation_id', 64);
            $table->unsignedBigInteger('automation_execution_id')->nullable()->index();
            $table->unsignedBigInteger('automation_node_execution_id')->nullable();
            $table->index('automation_node_execution_id', 'wp_side_fx_auto_node_exec_idx');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('idempotency_key', 128)->nullable()->index();
            $table->string('status', 32);
            $table->string('blocked_reason', 500)->nullable();
            $table->unsignedBigInteger('remote_post_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wordpress_side_effect_attempts');
    }
};
