<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('agent_apps')->where('app_key', 'seo-ops')->exists();
        if (!$exists) {
            DB::table('agent_apps')->insert([
                'ulid' => (string) Str::ulid(),
                'app_key' => 'seo-ops',
                'name' => 'SEO Ops',
                'addon_slug' => 'seo',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_apps')->where('app_key', 'seo-ops')->delete();
    }
};
