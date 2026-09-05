<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkResourceService;
use Omnichannel\Addons\Seeding\LinkIntelligence\UrlNormalizer;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SeedingTopicService
{
    public function __construct(
        private readonly LinkResourceService $linkResources = new LinkResourceService,
        private readonly UrlNormalizer $urlNormalizer = new UrlNormalizer,
        private readonly SeedingSocialPlatformDetector $platformDetector = new SeedingSocialPlatformDetector,
    ) {}

    /**
     * @return Collection<int, SeedingTopic>
     */
    public function listForSite(int $siteId, bool $archived = false): Collection
    {
        if ($siteId <= 0) {
            return collect();
        }

        $query = SeedingTopic::query()
            ->forSite($siteId)
            ->with('linkResources')
            ->orderByDesc('id');

        if ($archived) {
            $query->archived();
        } else {
            $query->notArchived();
        }

        return $query->get();
    }

    public function findForSite(int $siteId, int $topicId): ?SeedingTopic
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return null;
        }

        return SeedingTopic::query()
            ->forSite($siteId)
            ->with('linkResources')
            ->whereKey($topicId)
            ->first();
    }

    /**
     * @param  array{
     *     site_id: int,
     *     created_by?: int|null,
     *     full_text?: string,
     *     source_html?: string|null,
     *     social_url?: string|null,
     * }  $data
     */
    public function create(array $data): SeedingTopic
    {
        $siteId = (int) ($data['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_id is required');
        }

        // Empty full_text allowed for workspace local-first drafts.
        $fullText = array_key_exists('full_text', $data)
            ? (string) $data['full_text']
            : '';
        $socialUrl = $this->normalizeOptionalSocialUrl($data['social_url'] ?? null);
        $sourceHtml = $this->nullableString($data['source_html'] ?? null);

        return DB::connection('omi_seo_ai')->transaction(function () use ($data, $fullText, $siteId, $socialUrl, $sourceHtml): SeedingTopic {
            $topic = new SeedingTopic([
                'site_id' => $siteId,
                'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
                'full_text' => $fullText,
                'source_html' => $sourceHtml,
            ]);

            $this->applySocialUrl($topic, $socialUrl);
            $topic->save();

            $this->linkResources->syncTopicLinks($topic, $topic->full_text, $topic->source_html);

            return $topic->fresh(['linkResources']) ?? $topic;
        });
    }

    /**
     * Partial update — only provided keys are applied.
     *
     * @param  array{
     *     full_text?: string,
     *     source_html?: string|null,
     *     social_url?: string|null,
     *     archived?: bool,
     * }  $data
     */
    public function update(SeedingTopic $topic, array $data): SeedingTopic
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($topic, $data): SeedingTopic {
            $contentTouched = false;

            if (array_key_exists('full_text', $data)) {
                $topic->full_text = (string) $data['full_text'];
                $contentTouched = true;
            }

            if (array_key_exists('source_html', $data)) {
                $topic->source_html = $this->nullableString($data['source_html']);
                $contentTouched = true;
            }

            if (array_key_exists('social_url', $data)) {
                $socialUrl = $this->normalizeOptionalSocialUrl($data['social_url']);
                $this->applySocialUrl($topic, $socialUrl);
            }

            if (array_key_exists('archived', $data)) {
                if ((bool) $data['archived']) {
                    $topic->archived_at ??= Carbon::now();
                } else {
                    $topic->archived_at = null;
                }
            }

            $topic->save();

            if ($contentTouched) {
                $this->linkResources->syncTopicLinks($topic, $topic->full_text, $topic->source_html);
            }

            return $topic->fresh(['linkResources']) ?? $topic;
        });
    }

    public function updateSocialUrl(SeedingTopic $topic, ?string $socialUrl): SeedingTopic
    {
        return $this->update($topic, [
            'social_url' => $socialUrl,
        ]);
    }

    public function archive(SeedingTopic $topic): SeedingTopic
    {
        return $this->update($topic, ['archived' => true]);
    }

    public function restore(SeedingTopic $topic): SeedingTopic
    {
        return $this->update($topic, ['archived' => false]);
    }

    public function deleteDraft(SeedingTopic $topic): void
    {
        if (! $topic->isDraft()) {
            throw new InvalidArgumentException('Only draft topics can be deleted');
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($topic): void {
            $topic->linkResources()->detach();
            $topic->delete();
        });
    }

    /**
     * Exact full_text for clipboard — never source_html / rendered HTML.
     */
    public function copyPayload(SeedingTopic $topic): string
    {
        return (string) $topic->full_text;
    }

    public function archivedCountForSite(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        return (int) SeedingTopic::query()->forSite($siteId)->archived()->count();
    }

    private function applySocialUrl(SeedingTopic $topic, ?string $socialUrl): void
    {
        if ($socialUrl === null) {
            $topic->social_url = null;
            $topic->social_platform = null;
            if ($topic->status !== SeedingTopicStatus::Done) {
                $topic->status = SeedingTopicStatus::Draft;
            }

            return;
        }

        $topic->social_url = $socialUrl;
        $topic->social_platform = $this->platformDetector->detect($socialUrl);
        if ($topic->status !== SeedingTopicStatus::Done) {
            $topic->status = SeedingTopicStatus::Active;
        }
        if ($topic->published_at === null) {
            $topic->published_at = Carbon::now();
        }
    }

    private function normalizeOptionalSocialUrl(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        $normalized = $this->urlNormalizer->normalize($trimmed);
        if ($normalized === null) {
            throw new InvalidArgumentException('social_url must be a valid http/https URL');
        }

        return $normalized['original_url'];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
