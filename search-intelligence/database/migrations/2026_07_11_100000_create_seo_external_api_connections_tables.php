<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_gsc_master_connections')) {
            $schema->create('seo_gsc_master_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name');
                $table->string('status')->default('not_configured');
                $table->text('credentials')->nullable();
                $table->string('account_email')->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('is_global')->default(false);
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_gsc_property_mappings')) {
            $schema->create('seo_gsc_property_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('gsc_connection_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('property_url');
                $table->string('property_type')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['gsc_connection_id', 'site_id']);
            });
        }

        if (! $schema->hasTable('seo_dataforseo_connections')) {
            $schema->create('seo_dataforseo_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('login');
                $table->text('password');
                $table->string('default_location')->nullable();
                $table->string('default_language')->nullable();
                $table->decimal('balance', 12, 4)->nullable();
                $table->string('status')->default('not_configured');
                $table->boolean('is_global')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_dataforseo_connections');
        Schema::connection($this->connection)->dropIfExists('seo_gsc_property_mappings');
        Schema::connection($this->connection)->dropIfExists('seo_gsc_master_connections');
    }
};
