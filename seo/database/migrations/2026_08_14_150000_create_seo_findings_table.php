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
        if ($schema->hasTable('seo_findings')) {
            return;
        }

        $schema->create('seo_findings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('type', 64)->index();
            $table->string('severity', 16)->index();
            $table->string('entity_type', 32)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('fingerprint', 64)->index();
            $table->string('title', 255);
            $table->json('evidence')->nullable();
            $table->string('recommendation', 500)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_findings');
    }
};
