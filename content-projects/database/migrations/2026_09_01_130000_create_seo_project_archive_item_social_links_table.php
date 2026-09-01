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

        if ($schema->hasTable('seo_project_archive_item_social_links')) {
            return;
        }

        $schema->create('seo_project_archive_item_social_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('archive_item_id')->index();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('domain', 255)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['archive_item_id', 'url_hash'], 'seo_project_archive_item_social_links_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_archive_item_social_links');
    }
};
