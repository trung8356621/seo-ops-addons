<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Inbound;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncBatch;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use App\Models\Site;

final class SiteSyncStagingWriter
{
    /**
     * Idempotent stage by checksum. Returns existing row if already staged.
     */
    public function stage(
        Site $site,
        SiteSyncBatchData $batch,
        ?int $runId = null,
        bool $attemptScoped = false,
    ): SeoSiteSyncBatch {
        $payload = $batch->toArray();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $checksumMaterial = $attemptScoped && $runId !== null
            ? $json.'|run:'.$runId
            : $json;
        $checksum = hash('sha256', $checksumMaterial);

        $existing = SeoSiteSyncBatch::query()
            ->where('site_id', (int) $site->id)
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null) {
            if ($runId !== null && $existing->run_id === null) {
                $existing->forceFill(['run_id' => $runId])->save();
            }

            return $existing;
        }

        return SeoSiteSyncBatch::query()->create([
            'site_id' => (int) $site->id,
            'run_id' => $runId,
            'checksum' => $checksum,
            'mode' => $batch->mode,
            'cursor' => $batch->cursor,
            'has_more' => $batch->hasMore,
            'payload_json' => $json,
            'applied_at' => null,
        ]);
    }

    /**
     * Compatibility bridge: map legacy push-content items into a delta batch envelope.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function stageLegacyPushItems(Site $site, array $items, ?int $runId = null): SeoSiteSyncBatch
    {
        $batch = SiteSyncBatchData::fromArray([
            'schema' => SiteSyncSchema::VERSION,
            'mode' => SiteSyncSchema::MODE_DELTA,
            'run_token' => 'compat_push_'.uniqid('', true),
            'site_id_hint' => (int) $site->id,
            'cursor' => null,
            'has_more' => false,
            'articles' => $items,
            'links' => [],
            'provider_keywords' => $this->extractKeywordsFromLegacyItems($items),
            'scores' => [],
            'contacts_suggest' => [],
            'capability_ref' => ['compat' => true],
        ]);

        return $this->stage($site, $batch, $runId);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function extractKeywordsFromLegacyItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
            $phrase = trim((string) ($seo['focus_keyword'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $out[] = [
                'wordpress_id' => (int) ($item['wp_id'] ?? 0),
                'phrase' => $phrase,
                'source' => SiteSyncSchema::SOURCE_PROVIDER,
                'provider' => (string) ($seo['plugin'] ?? 'unknown'),
            ];
        }

        return $out;
    }
}
