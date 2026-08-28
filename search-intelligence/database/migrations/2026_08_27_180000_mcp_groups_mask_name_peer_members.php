<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MCP Group peers + mask_name (no primary hierarchy).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_mcp_topic_groups')) {
            $schema->create('seo_mcp_topic_groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('group_ref', 120);
                $table->string('mask_name', 255);
                $table->boolean('mask_name_manual')->default(false);
                $table->timestamps();
                $table->unique(['site_id', 'group_ref'], 'seo_mcp_topic_groups_site_ref_uq');
            });
        }

        if (! $schema->hasTable('seo_mcp_topic_group_members')) {
            $schema->create('seo_mcp_topic_group_members', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('group_ref', 120)->index();
                $table->string('cluster_key', 120);
                $table->timestamps();
                $table->unique(['site_id', 'cluster_key'], 'seo_mcp_topic_group_members_site_cluster_uq');
                $table->index(['site_id', 'group_ref'], 'seo_mcp_topic_group_members_site_group_idx');
            });

            return;
        }

        // Backfill groups from legacy primary-based rows, then drop is_primary.
        if ($schema->hasColumn('seo_mcp_topic_group_members', 'is_primary')) {
            $rows = DB::connection('omi_seo_ai')->table('seo_mcp_topic_group_members')->get();
            $byRef = [];
            foreach ($rows as $row) {
                $siteId = (int) ($row->site_id ?? 0);
                $ref = trim((string) ($row->group_ref ?? ''));
                $key = trim((string) ($row->cluster_key ?? ''));
                if ($siteId <= 0 || $ref === '' || $key === '') {
                    continue;
                }
                $bucket = $siteId.'|'.$ref;
                if (! isset($byRef[$bucket])) {
                    $byRef[$bucket] = [
                        'site_id' => $siteId,
                        'group_ref' => $ref,
                        'primary' => $ref,
                        'members' => [],
                    ];
                }
                $byRef[$bucket]['members'][] = $key;
                if ((bool) ($row->is_primary ?? false)) {
                    $byRef[$bucket]['primary'] = $key;
                    $byRef[$bucket]['group_ref'] = $key;
                }
            }

            $now = now();
            foreach ($byRef as $group) {
                $mask = '';
                if ($schema->hasTable('seo_topic_cluster_meta')) {
                    $mask = trim((string) DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')
                        ->where('site_id', $group['site_id'])
                        ->where('cluster_key', $group['primary'])
                        ->value('canonical_phrase'));
                }
                if ($mask === '') {
                    $mask = trim(str_replace('_', ' ', (string) $group['primary']));
                }
                $exists = DB::connection('omi_seo_ai')->table('seo_mcp_topic_groups')
                    ->where('site_id', $group['site_id'])
                    ->where('group_ref', $group['group_ref'])
                    ->exists();
                if (! $exists) {
                    DB::connection('omi_seo_ai')->table('seo_mcp_topic_groups')->insert([
                        'site_id' => $group['site_id'],
                        'group_ref' => $group['group_ref'],
                        'mask_name' => $mask !== '' ? $mask : $group['group_ref'],
                        'mask_name_manual' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                // Normalize member group_ref to primary/group_ref.
                DB::connection('omi_seo_ai')->table('seo_mcp_topic_group_members')
                    ->where('site_id', $group['site_id'])
                    ->whereIn('cluster_key', $group['members'])
                    ->update(['group_ref' => $group['group_ref'], 'updated_at' => $now]);
            }

            $schema->table('seo_mcp_topic_group_members', function (Blueprint $table): void {
                $table->dropColumn('is_primary');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_mcp_topic_groups')) {
            $schema->drop('seo_mcp_topic_groups');
        }
        if ($schema->hasTable('seo_mcp_topic_group_members')
            && ! $schema->hasColumn('seo_mcp_topic_group_members', 'is_primary')) {
            $schema->table('seo_mcp_topic_group_members', function (Blueprint $table): void {
                $table->boolean('is_primary')->default(false);
            });
        }
    }
};
