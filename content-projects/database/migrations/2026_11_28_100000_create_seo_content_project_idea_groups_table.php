<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaGroup;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;

/**
 * Idea Manager custom tabs/groups (1 system "Chung" + up to 4 manual groups per owner).
 * Backfills existing Ideas into their owner's "Chung" group idempotently without deleting data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_content_project_idea_groups')) {
            $schema->create('seo_content_project_idea_groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('owner_user_id');
                $table->string('name', 120);
                $table->boolean('is_system')->default(false);
                $table->unsignedTinyInteger('sort_order')->default(1);
                $table->timestamps();

                $table->index(['owner_user_id', 'sort_order'], 'scp_idea_groups_owner_sort_idx');
            });
        }

        $this->backfillExistingWorkspaces();
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_idea_groups');
    }

    private function backfillExistingWorkspaces(): void
    {
        $conn = DB::connection('omi_seo_ai');
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_idea_workspaces')) {
            return;
        }

        $workspaces = $conn->table('seo_content_project_idea_workspaces')
            ->get(['id', 'site_id', 'note_items', 'revision', 'updated_by']);

        if ($workspaces->isEmpty()) {
            return;
        }

        $siteIds = $workspaces->pluck('site_id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $siteOwners = [];
        if ($siteIds !== [] && Schema::hasTable('sites')) {
            $siteOwners = DB::table('sites')
                ->whereIn('id', $siteIds)
                ->pluck('user_id', 'id')
                ->all();
        }

        foreach ($workspaces as $ws) {
            $siteId = (int) $ws->site_id;
            $ownerUserId = (int) ($siteOwners[$siteId] ?? $ws->updated_by ?? 0);
            if ($ownerUserId <= 0) {
                continue;
            }

            $existingSystem = $conn->table('seo_content_project_idea_groups')
                ->where('owner_user_id', $ownerUserId)
                ->where('is_system', true)
                ->first(['id']);

            if ($existingSystem === null) {
                $now = now();
                $chungId = (int) $conn->table('seo_content_project_idea_groups')->insertGetId([
                    'owner_user_id' => $ownerUserId,
                    'name' => SeoContentProjectIdeaGroup::SYSTEM_GROUP_NAME,
                    'is_system' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $chungId = (int) $existingSystem->id;
            }

            $decoded = json_decode((string) ($ws->note_items ?? '[]'), true);
            if (! is_array($decoded) || $decoded === []) {
                continue;
            }

            $changed = false;
            $updatedItems = [];
            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ((int) ($item['idea_group_id'] ?? 0) <= 0) {
                    $item['idea_group_id'] = $chungId;
                    $changed = true;
                }
                $updatedItems[] = $item;
            }

            if ($changed) {
                $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($updatedItems);
                $conn->table('seo_content_project_idea_workspaces')
                    ->where('id', (int) $ws->id)
                    ->update([
                        'note_items' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'content_hash' => IdeaWorkspacePersistenceService::contentHash($normalized),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
