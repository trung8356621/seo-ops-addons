<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('prompts') || ! $schema->hasColumn('prompts', 'is_flow_prompt')) {
            return;
        }

        $prompts = DB::connection($this->connection)->table('prompts');
        $prompts->update(['is_flow_prompt' => false]);
        $prompts->whereIn('hook_key', [
            'product.gallery.parent.generate',
            'product.gallery.child.generate',
        ])->update(['is_flow_prompt' => true]);
    }

    public function down(): void
    {
        // The prior blanket workflow-reference classification was semantically invalid.
    }
};
