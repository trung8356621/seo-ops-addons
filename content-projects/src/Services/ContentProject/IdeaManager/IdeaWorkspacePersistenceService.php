<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectIdeaWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

/**
 * Canonical Idea Manager persistence — site-scoped note_items + optimistic revision.
 */
final class IdeaWorkspacePersistenceService
{
    public const MAX_NOTE_ITEMS = 500;

    public const STATUS_OK = 'ok';

    public const STATUS_NOOP = 'noop';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FORBIDDEN = 'forbidden';

    public const STATUS_INVALID = 'invalid';

    /**
     * @return array{
     *   status: string,
     *   site_id: int,
     *   revision: int,
     *   note_items: list<array<string, mixed>>,
     *   content_hash: string,
     *   updated_at: string|null,
     *   item_count: int
     * }
     */
    public function load(int $siteId, ?int $actorUserId = null): array
    {
        $this->assertCanAccess($siteId);

        $row = SeoContentProjectIdeaWorkspace::query()->where('site_id', $siteId)->first();
        if ($row === null) {
            $items = [];
            $hash = self::contentHash($items);

            return $this->snapshot($siteId, 0, $items, $hash, null, self::STATUS_OK);
        }

        $items = AuditNoteDnaNormalizer::normalizeNoteItems(
            is_array($row->note_items) ? $row->note_items : []
        );

        return $this->snapshot(
            $siteId,
            (int) $row->revision,
            $items,
            (string) $row->content_hash,
            $row->updated_at?->toIso8601String(),
            self::STATUS_OK,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return array{
     *   status: string,
     *   site_id: int,
     *   revision: int,
     *   note_items: list<array<string, mixed>>,
     *   content_hash: string,
     *   updated_at: string|null,
     *   item_count: int,
     *   message?: string
     * }
     */
    public function save(int $siteId, array $noteItems, int $expectedRevision, ?int $actorUserId = null): array
    {
        $this->assertCanAccess($siteId);

        $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        if (count($normalized) > self::MAX_NOTE_ITEMS) {
            return array_merge(
                $this->snapshot($siteId, $expectedRevision, $normalized, self::contentHash($normalized), null, self::STATUS_INVALID),
                ['message' => 'too_many_items'],
            );
        }

        $incomingHash = self::contentHash($normalized);

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $normalized, $expectedRevision, $actorUserId, $incomingHash) {
            /** @var SeoContentProjectIdeaWorkspace|null $row */
            $row = SeoContentProjectIdeaWorkspace::query()
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                if ($expectedRevision !== 0) {
                    return array_merge(
                        $this->snapshot($siteId, 0, [], self::contentHash([]), null, self::STATUS_CONFLICT),
                        ['message' => 'revision_mismatch'],
                    );
                }

                if ($incomingHash === self::contentHash([])) {
                    return $this->snapshot($siteId, 0, [], $incomingHash, null, self::STATUS_NOOP);
                }

                $created = SeoContentProjectIdeaWorkspace::query()->create([
                    'site_id' => $siteId,
                    'note_items' => $normalized,
                    'revision' => 1,
                    'content_hash' => $incomingHash,
                    'updated_by' => $actorUserId,
                ]);

                return $this->snapshot(
                    $siteId,
                    (int) $created->revision,
                    $normalized,
                    (string) $created->content_hash,
                    $created->updated_at?->toIso8601String(),
                    self::STATUS_OK,
                );
            }

            // TOCTOU-safe revision recheck under row lock.
            if ((int) $row->revision !== $expectedRevision) {
                $serverItems = AuditNoteDnaNormalizer::normalizeNoteItems(
                    is_array($row->note_items) ? $row->note_items : []
                );

                return array_merge(
                    $this->snapshot(
                        $siteId,
                        (int) $row->revision,
                        $serverItems,
                        (string) $row->content_hash,
                        $row->updated_at?->toIso8601String(),
                        self::STATUS_CONFLICT,
                    ),
                    ['message' => 'revision_mismatch'],
                );
            }

            if ((string) $row->content_hash === $incomingHash) {
                return $this->snapshot(
                    $siteId,
                    (int) $row->revision,
                    AuditNoteDnaNormalizer::normalizeNoteItems(
                        is_array($row->note_items) ? $row->note_items : []
                    ),
                    (string) $row->content_hash,
                    $row->updated_at?->toIso8601String(),
                    self::STATUS_NOOP,
                );
            }

            $row->note_items = $normalized;
            $row->content_hash = $incomingHash;
            $row->revision = (int) $row->revision + 1;
            $row->updated_by = $actorUserId;
            $row->save();

            return $this->snapshot(
                $siteId,
                (int) $row->revision,
                $normalized,
                (string) $row->content_hash,
                $row->updated_at?->toIso8601String(),
                self::STATUS_OK,
            );
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function contentHash(array $items): string
    {
        $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($items);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function assertCanAccess(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new RuntimeException('invalid_site');
        }
        if (! SeoAccessControl::canManageContentProjectWorkflow()) {
            throw new RuntimeException(self::STATUS_FORBIDDEN);
        }
        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException(self::STATUS_FORBIDDEN);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *   status: string,
     *   site_id: int,
     *   revision: int,
     *   note_items: list<array<string, mixed>>,
     *   content_hash: string,
     *   updated_at: string|null,
     *   item_count: int
     * }
     */
    private function snapshot(
        int $siteId,
        int $revision,
        array $items,
        string $hash,
        ?string $updatedAt,
        string $status,
    ): array {
        return [
            'status' => $status,
            'site_id' => $siteId,
            'revision' => $revision,
            'note_items' => array_values($items),
            'content_hash' => $hash,
            'updated_at' => $updatedAt,
            'item_count' => count($items),
        ];
    }
}
