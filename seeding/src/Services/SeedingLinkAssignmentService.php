<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Models\SeedingLinkAssignment;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

/**
 * DB-backed Creator link assignments — master list snapshotted into Topics at share.
 */
final class SeedingLinkAssignmentService
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
    ) {}

    public function tableReady(): bool
    {
        return Schema::connection(SeedingServiceConfig::CONNECTION)
            ->hasTable('seeding_link_assignments');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOwner(int $ownerUserId, bool $activeOnly = false): array
    {
        if ($ownerUserId <= 0 || ! $this->tableReady()) {
            return [];
        }

        $query = SeedingLinkAssignment::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->ownedBy($ownerUserId)
            ->orderByDesc('id');

        if ($activeOnly) {
            $query->active();
        }

        return $query->get()
            ->map(static fn (SeedingLinkAssignment $row): array => $row->toApiArray())
            ->all();
    }

    /**
     * @param  array{title?: string|null, url: string, target_per_day?: int, is_active?: bool}  $payload
     */
    public function create(int $ownerUserId, array $payload): SeedingLinkAssignment
    {
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('Thiếu owner');
        }
        if (! $this->tableReady()) {
            throw new InvalidArgumentException('Bảng link assignment chưa sẵn sàng');
        }

        $url = $this->requireUrl($payload['url'] ?? null);
        $title = $this->nullableTitle($payload['title'] ?? null);
        $target = max(1, (int) ($payload['target_per_day'] ?? 5));

        $row = new SeedingLinkAssignment([
            'installation_id' => $this->resolver->installationNamespace(),
            'owner_user_id' => $ownerUserId,
            'title' => $title,
            'url' => $url,
            'target_per_day' => $target,
            'is_active' => ($payload['is_active'] ?? true) !== false,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  array{title?: string|null, url?: string|null, target_per_day?: int|null, is_active?: bool|null}  $payload
     */
    public function update(int $ownerUserId, int $assignmentId, array $payload): SeedingLinkAssignment
    {
        $row = $this->findOwned($ownerUserId, $assignmentId);

        if (array_key_exists('url', $payload) && $payload['url'] !== null) {
            $row->url = $this->requireUrl($payload['url']);
        }
        if (array_key_exists('title', $payload)) {
            $row->title = $this->nullableTitle($payload['title']);
        }
        if (array_key_exists('target_per_day', $payload) && $payload['target_per_day'] !== null) {
            $row->target_per_day = max(1, (int) $payload['target_per_day']);
        }
        if (array_key_exists('is_active', $payload) && $payload['is_active'] !== null) {
            $row->is_active = (bool) $payload['is_active'];
        }

        $row->save();

        return $row->fresh() ?? $row;
    }

    public function delete(int $ownerUserId, int $assignmentId): void
    {
        $row = $this->findOwned($ownerUserId, $assignmentId);
        $row->delete();
    }

    /**
     * Resolve public ids (assign:N) / numeric ids into Topic snapshot rows.
     * Prefer DB values when the assignment still exists; otherwise keep payload fields.
     *
     * @param  list<mixed>  $links
     * @return list<array<string, mixed>>
     */
    public function snapshotLinksForShare(array $links, int $ownerUserId): array
    {
        if ($links === []) {
            return [];
        }

        $byPublicId = $this->indexOwnedAssignments($ownerUserId);
        $out = [];

        foreach ($links as $link) {
            if (is_string($link)) {
                $url = trim($link);
                if ($url === '') {
                    continue;
                }
                $out[] = [
                    'id' => 'tlink:'.md5(strtolower(rtrim($url, '/'))),
                    'url' => $url,
                    'title' => null,
                    'label' => null,
                    'target_per_day' => 0,
                ];
                continue;
            }
            if (! is_array($link)) {
                continue;
            }

            $publicId = $this->extractPublicId($link);
            $db = $publicId !== null ? ($byPublicId[$publicId] ?? null) : null;

            if ($db instanceof SeedingLinkAssignment) {
                $snap = $db->toTopicSnapshot();
                // Composer may override snapshot fields for this Topic only (does not mutate master).
                $payloadTitle = isset($link['title'])
                    ? $this->nullableTitle($link['title'])
                    : (isset($link['label']) ? $this->nullableTitle($link['label']) : null);
                if ($payloadTitle !== null) {
                    $snap['title'] = $payloadTitle;
                    $snap['label'] = $payloadTitle;
                }
                if (isset($link['url']) && trim((string) $link['url']) !== '') {
                    try {
                        $snap['url'] = $this->requireUrl($link['url']);
                    } catch (InvalidArgumentException) {
                        // keep DB url
                    }
                }
                if (isset($link['target_per_day']) && (int) $link['target_per_day'] > 0) {
                    $snap['target_per_day'] = max(1, (int) $link['target_per_day']);
                }
                $out[] = $snap;
                continue;
            }

            $url = trim((string) ($link['url'] ?? $link['normalized_url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $title = isset($link['title']) ? $this->nullableTitle($link['title']) : (
                isset($link['label']) ? $this->nullableTitle($link['label']) : null
            );
            $id = $publicId !== null && $publicId !== ''
                ? $publicId
                : ('tlink:'.md5(strtolower(rtrim($url, '/'))));

            $out[] = [
                'id' => $id,
                'url' => $url,
                'normalized_url' => isset($link['normalized_url']) ? (string) $link['normalized_url'] : null,
                'title' => $title,
                'label' => $title,
                'target_per_day' => max(0, (int) ($link['target_per_day'] ?? 0)),
                'preview_title' => $link['preview_title'] ?? null,
                'preview_description' => $link['preview_description'] ?? null,
                'preview_image_url' => $link['preview_image_url'] ?? null,
                'preview_domain' => $link['preview_domain'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * One-time import of legacy local seed_links into DB for this owner.
     *
     * @param  list<array<string, mixed>>  $localLinks
     * @return list<array<string, mixed>>
     */
    public function importLocalLinks(int $ownerUserId, array $localLinks): array
    {
        if ($ownerUserId <= 0 || ! $this->tableReady()) {
            return [];
        }

        $existingUrls = SeedingLinkAssignment::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->ownedBy($ownerUserId)
            ->pluck('url')
            ->map(static fn ($u): string => strtolower(rtrim((string) $u, '/')))
            ->all();
        $existingSet = array_fill_keys($existingUrls, true);

        $created = [];
        foreach ($localLinks as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $url = trim((string) ($raw['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $key = strtolower(rtrim($url, '/'));
            if (isset($existingSet[$key])) {
                continue;
            }
            $title = $this->nullableTitle($raw['title'] ?? $raw['label'] ?? null);
            $target = max(1, (int) ($raw['target_per_day'] ?? $raw['daily_limit'] ?? 5));
            $row = $this->create($ownerUserId, [
                'title' => $title,
                'url' => $url,
                'target_per_day' => $target,
                'is_active' => ($raw['is_active'] ?? true) !== false,
            ]);
            $existingSet[$key] = true;
            $created[] = $row->toApiArray();
        }

        return $created;
    }

    private function findOwned(int $ownerUserId, int $assignmentId): SeedingLinkAssignment
    {
        if (! $this->tableReady()) {
            throw new InvalidArgumentException('Bảng link assignment chưa sẵn sàng');
        }

        $row = SeedingLinkAssignment::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->ownedBy($ownerUserId)
            ->whereKey($assignmentId)
            ->first();

        if (! $row instanceof SeedingLinkAssignment) {
            throw new InvalidArgumentException('Link assignment không tồn tại');
        }

        return $row;
    }

    /**
     * @return array<string, SeedingLinkAssignment>
     */
    private function indexOwnedAssignments(int $ownerUserId): array
    {
        if ($ownerUserId <= 0 || ! $this->tableReady()) {
            return [];
        }

        /** @var Collection<int, SeedingLinkAssignment> $rows */
        $rows = SeedingLinkAssignment::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->ownedBy($ownerUserId)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->publicId()] = $row;
            $map[(string) (int) $row->id] = $row;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function extractPublicId(array $link): ?string
    {
        $raw = trim((string) ($link['id'] ?? ''));
        if ($raw !== '') {
            if (preg_match('/^assign:(\d+)$/', $raw, $m) === 1) {
                return 'assign:'.(int) $m[1];
            }
            if (ctype_digit($raw)) {
                return 'assign:'.(int) $raw;
            }

            return $raw;
        }

        $assignmentId = (int) ($link['assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            return 'assign:'.$assignmentId;
        }

        return null;
    }

    private function requireUrl(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            throw new InvalidArgumentException('URL không được trống');
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL không hợp lệ');
        }

        return $url;
    }

    private function nullableTitle(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 255);
    }
}
