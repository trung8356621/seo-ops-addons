<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager-owned social login accounts per Domain/Site (omi_seeding).
 * Credentials encrypted at rest via Eloquent casts — no cross-DB FK to core sites.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seeding_social_accounts')) {
            return;
        }

        $schema->create('seeding_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 64)->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('domain', 255);
            $table->string('platform', 32);
            $table->string('label', 255)->nullable();
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->json('meta_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('credential_updated_at')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'status'], 'seeding_social_acct_inst_status_idx');
            $table->index(['installation_id', 'site_id', 'platform'], 'seeding_social_acct_inst_site_plat_idx');
            $table->index(['installation_id', 'domain'], 'seeding_social_acct_inst_domain_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seeding_social_accounts');
    }
};
