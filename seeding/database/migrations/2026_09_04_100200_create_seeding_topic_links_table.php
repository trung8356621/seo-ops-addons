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
        if ($schema->hasTable('seeding_topic_links')) {
            return;
        }

        $schema->create('seeding_topic_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('topic_id')->index();
            $table->unsignedBigInteger('link_resource_id')->index();
            $table->timestamps();

            $table->unique(['topic_id', 'link_resource_id'], 'seeding_topic_links_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seeding_topic_links');
    }
};
