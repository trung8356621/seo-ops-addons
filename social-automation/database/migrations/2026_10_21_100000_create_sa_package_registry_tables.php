<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package Registry — Widget/Workflow immutable releases on omi_social_automation.
 * No cross-database foreign keys. Payload is structured definition JSON (never executed).
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_key', 128);
            $table->string('kind', 32); // widget | workflow
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('package_key');
            $table->index('kind');
        });

        Schema::connection($this->connection)->create('sa_package_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->string('version', 64);
            $table->string('content_digest', 64); // sha256 hex
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('runtime_compatibility')->nullable();
            $table->json('manifest'); // structured definition — not executable code
            $table->string('status', 32)->default('published'); // published | yanked
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'version']);
            $table->index('content_digest');
            $table->index('status');
        });

        Schema::connection($this->connection)->create('sa_package_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id');
            $table->string('dependency_package_key', 128);
            $table->string('dependency_version', 64);
            $table->string('dependency_digest', 64);
            $table->timestamps();

            $table->unique(['release_id', 'dependency_package_key'], 'sa_pkg_deps_release_key_unique');
            $table->index('dependency_package_key');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_package_dependencies');
        Schema::connection($this->connection)->dropIfExists('sa_package_releases');
        Schema::connection($this->connection)->dropIfExists('sa_packages');
    }
};
