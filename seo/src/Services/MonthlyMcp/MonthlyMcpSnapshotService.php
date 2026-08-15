<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSnapshotStatus;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use RuntimeException;
use Throwable;

final class MonthlyMcpSnapshotService
{
    public function __construct(
        private readonly MonthlyMcpSourceRegistry $registry,
        private readonly McpPeriodPolicy $policy,
    ) {}

    public function capture(SeoMcpPeriod $period, Site $site, string $sourceKey): SeoMcpSourceSnapshot
    {
        $this->policy->assertOpen($period);
        $source = $this->registry->get($sourceKey);
        try {
            $payload = $source->build($site, $period);

            return $this->persistSuccess($period, $site, $payload, $source->schemaVersion());
        } catch (Throwable $e) {
            return $this->persistFailure($period, $site, $sourceKey, $source->schemaVersion(), $e->getMessage());
        }
    }

    /**
     * @param  list<string>|null  $sourceKeys
     * @return list<SeoMcpSourceSnapshot>
     */
    public function captureMany(SeoMcpPeriod $period, Site $site, ?array $sourceKeys = null): array
    {
        $keys = $sourceKeys ?? array_map(
            static fn ($source): string => $source->key(),
            $this->registry->all(),
        );
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->capture($period, $site, $key);
        }

        return $out;
    }

    public function displayStatus(SeoMcpSourceSnapshot $snapshot, Site $site): string
    {
        if ($snapshot->statusEnum() === McpSnapshotStatus::Failed) {
            return McpSnapshotStatus::Failed->value;
        }
        $sourceKey = (string) $snapshot->source;
        try {
            $liveUpdated = $this->registry->get($sourceKey)->sourceUpdatedAt($site);
        } catch (RuntimeException) {
            $liveUpdated = null;
        }
        $snapAt = $snapshot->source_updated_at?->toIso8601String();
        if (MonthlyMcpFreshness::isNewer($liveUpdated, $snapAt)) {
            return McpSnapshotStatus::Stale->value;
        }

        return McpSnapshotStatus::Current->value;
    }

    private function persistSuccess(
        SeoMcpPeriod $period,
        Site $site,
        MonthlyMcpSourcePayload $payload,
        string $schemaVersion,
    ): SeoMcpSourceSnapshot {
        $row = $this->locate($period, (int) $site->id, $payload->source->value, $schemaVersion);
        if ($row instanceof SeoMcpSourceSnapshot
            && (string) $row->content_hash === $payload->contentHash
            && $row->isUsable()
        ) {
            $row->generated_at = now();
            $row->source_updated_at = MonthlyMcpFreshness::parse($payload->sourceUpdatedAt);
            $row->status = McpSnapshotStatus::Current->value;
            $row->error_message = null;
            $row->save();

            return $row;
        }

        $attrs = [
            'status' => McpSnapshotStatus::Current->value,
            'generated_at' => now(),
            'source_updated_at' => MonthlyMcpFreshness::parse($payload->sourceUpdatedAt),
            'metrics_json' => $payload->metrics,
            'summary_json' => $payload->summary,
            'context_json' => $payload->context,
            'content_hash' => $payload->contentHash,
            'error_message' => null,
        ];
        if ($row instanceof SeoMcpSourceSnapshot) {
            $row->fill($attrs);
            $row->save();

            return $row;
        }

        return SeoMcpSourceSnapshot::query()->create(array_merge($attrs, [
            'period_id' => $period->id,
            'site_id' => (int) $site->id,
            'source' => $payload->source->value,
            'schema_version' => $schemaVersion,
        ]));
    }

    private function persistFailure(
        SeoMcpPeriod $period,
        Site $site,
        string $sourceKey,
        string $schemaVersion,
        string $message,
    ): SeoMcpSourceSnapshot {
        $row = $this->locate($period, (int) $site->id, $sourceKey, $schemaVersion);
        $attrs = [
            'status' => McpSnapshotStatus::Failed->value,
            'generated_at' => now(),
            'error_message' => mb_substr($message, 0, 500),
        ];
        if ($row instanceof SeoMcpSourceSnapshot) {
            $row->fill($attrs);
            $row->save();

            return $row;
        }

        return SeoMcpSourceSnapshot::query()->create(array_merge($attrs, [
            'period_id' => $period->id,
            'site_id' => (int) $site->id,
            'source' => $sourceKey,
            'schema_version' => $schemaVersion,
            'metrics_json' => null,
            'summary_json' => null,
            'context_json' => null,
        ]));
    }

    private function locate(SeoMcpPeriod $period, int $siteId, string $source, string $schemaVersion): ?SeoMcpSourceSnapshot
    {
        return SeoMcpSourceSnapshot::query()
            ->where('period_id', $period->id)
            ->where('site_id', $siteId)
            ->where('source', $source)
            ->where('schema_version', $schemaVersion)
            ->first();
    }

    public function find(SeoMcpPeriod $period, int $siteId, McpSourceKey $source): ?SeoMcpSourceSnapshot
    {
        return SeoMcpSourceSnapshot::query()
            ->where('period_id', $period->id)
            ->where('site_id', $siteId)
            ->where('source', $source->value)
            ->first();
    }
}
