<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_topic_cluster_meta')) {
            return;
        }

        if (! $schema->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $schema->table('seo_topic_cluster_meta', function (Blueprint $table): void {
                $table->string('canonical_source', 16)->default('auto')->after('needs_review');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_topic_cluster_meta') && $schema->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $schema->table('seo_topic_cluster_meta', function (Blueprint $table): void {
                $table->dropColumn('canonical_source');
            });
        }
    }
};
