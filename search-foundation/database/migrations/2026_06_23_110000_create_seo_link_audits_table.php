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
        if (Schema::connection($this->connection)->hasTable('seo_link_audits')) {
            return;
        }

        Schema::connection($this->connection)->create('seo_link_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->char('target_url_hash', 64);
            $table->text('target_url');
            $table->enum('status', ['active', 'needs_audit', 'ignored', 'broken'])->default('active');
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'target_url_hash'], 'seo_link_audits_site_url_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_link_audits');
    }
};
