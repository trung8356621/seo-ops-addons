<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

use App\Models\Site;
use InvalidArgumentException;

/**
 * Site-scoped dynamic CTA template pool.
 *
 * Persistence: core site_meta key {@see self::META_KEY}.
 * Slot IDs are immutable (CTA_N). Delete removes the slot; append allocates next IDs.
 * Article snapshots are independent of this pool.
 */
final class SiteCtaTemplatePoolService
{
    public const META_KEY = 'seo_site_cta_template_pool';

    /** Default batch size for each AI generate append. */
    public const BATCH_SIZE = 10;

    /** @deprecated Use BATCH_SIZE — kept for older callers/tests. */
    public const SLOT_COUNT = 10;

    public const STATUS_EMPTY = 'empty';

    public const STATUS_READY = 'ready';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_AI = 'ai';

    /**
     * @return array{
     *     version: int,
     *     site_id: int,
     *     next_slot_seq: int,
     *     templates: list<array{
     *         slot: int,
     *         slot_id: string,
     *         intent: string,
     *         content: string,
     *         status: string,
     *         source: string,
     *         updated_at: ?string,
     *         generated_at: ?string
     *     }>,
     *     last_generated_at: ?string
     * }
     */
    public function getPool(Site|int $site): array
    {
        $site = $this->resolveSite($site);
        $raw = $site->getMeta(self::META_KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return $this->emptyPool((int) $site->id);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->emptyPool((int) $site->id);
        }

        return $this->normalizePool($decoded, (int) $site->id);
    }

    /**
     * Replace/update templates by immutable slot id. Does not invent empty slots.
     *
     * @param  list<array{slot?: int|string, intent?: string, content?: string, status?: string, source?: string, updated_at?: ?string, generated_at?: ?string}>  $templates
     * @return array<string, mixed>
     */
    public function savePool(Site|int $site, array $templates, ?string $lastGeneratedAt = null): array
    {
        $site = $this->resolveSite($site);
        $current = $this->getPool($site);
        $bySlot = [];
        foreach ($current['templates'] as $existing) {
            $bySlot[(int) $existing['slot']] = $existing;
        }

        foreach ($templates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slot = (int) ($row['slot'] ?? 0);
            if ($slot < 1) {
                continue;
            }
            if (! isset($bySlot[$slot])) {
                // Do not create new slots via save — append path only.
                continue;
            }
            $existing = $bySlot[$slot];
            $newContent = array_key_exists('content', $row)
                ? trim((string) $row['content'])
                : (string) $existing['content'];
            $newIntent = array_key_exists('intent', $row)
                ? trim((string) $row['intent'])
                : (string) $existing['intent'];
            $source = (string) $existing['source'];
            if ($newContent !== (string) $existing['content'] || $newIntent !== (string) $existing['intent']) {
                $source = 'manual';
            }
            $bySlot[$slot] = $this->normalizeTemplate([
                'slot' => $slot,
                'intent' => $newIntent,
                'content' => $newContent,
                'source' => $source,
                'updated_at' => now()->toIso8601String(),
                'generated_at' => $existing['generated_at'],
            ], $slot);
        }

        $ordered = $this->orderedTemplates(array_values($bySlot));
        $payload = [
            'version' => 2,
            'site_id' => (int) $site->id,
            'next_slot_seq' => max(
                (int) $current['next_slot_seq'],
                $this->maxSlot($ordered) + 1,
            ),
            'templates' => $ordered,
            'last_generated_at' => $lastGeneratedAt ?? $current['last_generated_at'],
        ];

        return $this->persist($site, $payload);
    }

    /**
     * @param  array{intent?: string, content?: string, source?: string}  $patch
     * @return array<string, mixed>
     */
    public function saveSlot(Site|int $site, int $slot, array $patch): array
    {
        if ($slot < 1) {
            throw new InvalidArgumentException('CTA slot must be a positive integer.');
        }

        $pool = $this->getPool($site);
        $found = false;
        $templates = [];
        foreach ($pool['templates'] as $template) {
            if ((int) $template['slot'] !== $slot) {
                $templates[] = $template;
                continue;
            }
            $found = true;
            $content = array_key_exists('content', $patch)
                ? trim((string) $patch['content'])
                : (string) $template['content'];
            $intent = array_key_exists('intent', $patch)
                ? trim((string) $patch['intent'])
                : (string) $template['intent'];
            $source = array_key_exists('source', $patch)
                ? trim((string) $patch['source'])
                : 'manual';
            $templates[] = $this->normalizeTemplate([
                'slot' => $slot,
                'intent' => $intent,
                'content' => $content,
                'source' => $source !== '' ? $source : 'manual',
                'updated_at' => now()->toIso8601String(),
                'generated_at' => $template['generated_at'],
            ], $slot);
        }

        if (! $found) {
            throw new InvalidArgumentException('CTA slot '.$slot.' does not exist in the pool.');
        }

        return $this->persist($site, [
            'version' => 2,
            'site_id' => (int) ($site instanceof Site ? $site->id : $site),
            'next_slot_seq' => (int) $pool['next_slot_seq'],
            'templates' => $this->orderedTemplates($templates),
            'last_generated_at' => $pool['last_generated_at'],
        ]);
    }

    /**
     * Delete one immutable slot. Does not renumber remaining IDs or reuse the slot.
     *
     * @return array<string, mixed>
     */
    public function deleteSlot(Site|int $site, int $slot): array
    {
        if ($slot < 1) {
            throw new InvalidArgumentException('CTA slot must be a positive integer.');
        }

        $site = $this->resolveSite($site);
        $pool = $this->getPool($site);
        $templates = [];
        $removed = false;
        foreach ($pool['templates'] as $template) {
            if ((int) $template['slot'] === $slot) {
                $removed = true;
                continue;
            }
            $templates[] = $template;
        }
        if (! $removed) {
            throw new InvalidArgumentException('CTA slot '.$slot.' does not exist in the pool.');
        }

        return $this->persist($site, [
            'version' => 2,
            'site_id' => (int) $site->id,
            'next_slot_seq' => (int) $pool['next_slot_seq'],
            'templates' => $this->orderedTemplates($templates),
            'last_generated_at' => $pool['last_generated_at'],
        ]);
    }

    /**
     * Append validated AI proposals as new immutable slots (never overwrite).
     *
     * @param  list<array{slot?: int, intent?: string, content: string}>  $proposals
     * @param  list<int>  $overwriteSlots  Ignored — append-only.
     * @return array<string, mixed>
     */
    public function applyProposals(Site|int $site, array $proposals, array $overwriteSlots = []): array
    {
        unset($overwriteSlots);

        return $this->appendBatch($site, $proposals);
    }

    /**
     * @param  list<array{intent?: string, content: string}>  $proposals
     * @return array<string, mixed>
     */
    public function appendBatch(Site|int $site, array $proposals): array
    {
        $site = $this->resolveSite($site);
        $pool = $this->getPool($site);
        $existingNorm = [];
        foreach ($pool['templates'] as $template) {
            $norm = $this->normalizeContentKey((string) $template['content']);
            if ($norm !== '') {
                $existingNorm[$norm] = true;
            }
        }

        $now = now()->toIso8601String();
        $next = max(1, (int) $pool['next_slot_seq']);
        $templates = $pool['templates'];
        $appended = 0;

        foreach ($proposals as $row) {
            if (! is_array($row)) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $norm = $this->normalizeContentKey($content);
            if ($norm !== '' && isset($existingNorm[$norm])) {
                continue;
            }
            $slot = $next;
            $next++;
            $templates[] = $this->normalizeTemplate([
                'slot' => $slot,
                'intent' => trim((string) ($row['intent'] ?? '')),
                'content' => $content,
                'source' => self::STATUS_AI,
                'updated_at' => $now,
                'generated_at' => $now,
            ], $slot);
            if ($norm !== '') {
                $existingNorm[$norm] = true;
            }
            $appended++;
        }

        if ($appended === 0) {
            return $pool;
        }

        return $this->persist($site, [
            'version' => 2,
            'site_id' => (int) $site->id,
            'next_slot_seq' => $next,
            'templates' => $this->orderedTemplates($templates),
            'last_generated_at' => $now,
        ]);
    }

    public function slotId(int $slot): string
    {
        return 'CTA_'.$slot;
    }

    public function nextSlotSeq(Site|int $site): int
    {
        return (int) $this->getPool($site)['next_slot_seq'];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPool(int $siteId): array
    {
        return [
            'version' => 2,
            'site_id' => $siteId,
            'next_slot_seq' => 1,
            'templates' => [],
            'last_generated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePool(array $payload, int $siteId): array
    {
        $bySlot = [];
        $rows = is_array($payload['templates'] ?? null) ? $payload['templates'] : [];
        $version = (int) ($payload['version'] ?? 1);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slot = (int) ($row['slot'] ?? 0);
            if ($slot < 1) {
                continue;
            }
            $template = $this->normalizeTemplate($row, $slot);
            // v1 padded empty slots — drop empties when migrating to dynamic pool.
            if ($version < 2 && $template['content'] === '') {
                continue;
            }
            if ($template['content'] === '' && $version >= 2) {
                continue;
            }
            $bySlot[$slot] = $template;
        }

        $templates = $this->orderedTemplates(array_values($bySlot));
        $maxSlot = $this->maxSlot($templates);
        $storedNext = (int) ($payload['next_slot_seq'] ?? 0);
        $next = max($storedNext, $maxSlot + 1, 1);
        if ($version < 2 && $maxSlot === 0) {
            $next = 1;
        }
        if ($version < 2 && $maxSlot > 0 && $storedNext < 1) {
            // After v1 full 1..10 fill, continue at 11.
            $next = max($maxSlot + 1, 11);
        }

        $lastGenerated = $payload['last_generated_at'] ?? null;

        return [
            'version' => 2,
            'site_id' => $siteId,
            'next_slot_seq' => $next,
            'templates' => $templates,
            'last_generated_at' => is_string($lastGenerated) && $lastGenerated !== '' ? $lastGenerated : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     slot: int,
     *     slot_id: string,
     *     intent: string,
     *     content: string,
     *     status: string,
     *     source: string,
     *     updated_at: ?string,
     *     generated_at: ?string
     * }
     */
    private function normalizeTemplate(array $row, int $slot): array
    {
        $content = trim((string) ($row['content'] ?? ''));
        $source = trim((string) ($row['source'] ?? ''));
        if ($source === '') {
            $source = $content === '' ? '' : 'manual';
        }
        $status = match (true) {
            $content === '' => self::STATUS_EMPTY,
            $source === self::STATUS_AI => self::STATUS_AI,
            default => self::STATUS_MANUAL,
        };

        $updatedAt = $row['updated_at'] ?? null;
        $generatedAt = $row['generated_at'] ?? null;

        return [
            'slot' => $slot,
            'slot_id' => $this->slotId($slot),
            'intent' => trim((string) ($row['intent'] ?? '')),
            'content' => $content,
            'status' => $status,
            'source' => $source,
            'updated_at' => is_string($updatedAt) && $updatedAt !== '' ? $updatedAt : null,
            'generated_at' => is_string($generatedAt) && $generatedAt !== '' ? $generatedAt : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     * @return list<array<string, mixed>>
     */
    private function orderedTemplates(array $templates): array
    {
        usort($templates, static fn (array $a, array $b): int => ((int) $a['slot']) <=> ((int) $b['slot']));

        return array_values($templates);
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     */
    private function maxSlot(array $templates): int
    {
        $max = 0;
        foreach ($templates as $template) {
            $max = max($max, (int) ($template['slot'] ?? 0));
        }

        return $max;
    }

    private function normalizeContentKey(string $content): string
    {
        $content = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));

        return $content;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function persist(Site $site, array $payload): array
    {
        $normalized = $this->normalizePool($payload, (int) $site->id);
        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'],
        );

        return $normalized;
    }

    private function resolveSite(Site|int $site): Site
    {
        if ($site instanceof Site) {
            return $site;
        }

        return Site::query()->findOrFail($site);
    }
}
