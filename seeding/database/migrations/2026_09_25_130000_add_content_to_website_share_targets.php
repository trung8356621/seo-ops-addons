<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('website_share_targets')) {
            return;
        }

        $columns = $schema->getColumnListing('website_share_targets');
        $schema->table('website_share_targets', function (Blueprint $table) use ($columns): void {
            if (! in_array('share_content', $columns, true)) {
                $table->longText('share_content')->nullable();
            }
            if (! in_array('content_generated_at', $columns, true)) {
                $table->timestamp('content_generated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('website_share_targets')) {
            return;
        }

        $schema->table('website_share_targets', function (Blueprint $table): void {
            $table->dropColumn(['share_content', 'content_generated_at']);
        });
    }
};
