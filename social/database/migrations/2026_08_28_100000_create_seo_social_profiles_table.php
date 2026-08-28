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
        if ($schema->hasTable('seo_social_profiles')) {
            return;
        }

        $schema->create('seo_social_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('platform', 32);
            $table->string('display_name', 191);
            $table->string('profile_url', 2048);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'is_active']);
            $table->index(['site_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_social_profiles');
    }
};
