<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Trạng thái publish/SEO từ client bundle (không phụ thuộc Livewire).
 */
final readonly class ArticleEditorSaveContext
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $postType,
        public string $status,
        public string $visibility,
        public string $publishDay,
        public string $publishMonth,
        public string $publishYear,
        public string $publishHour,
        public string $publishMinute,
        public string $seoMetaDescription,
        public string $focusKeyword,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     */
    public static function fromBundle(SeoArticle $article, array $bundle): self
    {
        $meta = is_array($bundle['article_meta'] ?? null) ? $bundle['article_meta'] : [];
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $title = trim((string) ($meta['title'] ?? $article->title ?? ''));
        $slug = trim((string) ($meta['slug'] ?? $article->slug ?? ''));
        $seoMetaDescription = trim((string) ($meta['seo_meta_description'] ?? ''));
        $focusKeyword = trim((string) ($meta['focus_keyword'] ?? ''));

        if ($seoMetaDescription === '') {
            $article->loadMissing('articleMetas');
            $seoMetaDescription = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', 'seo_meta_description')?->meta_value
                ?? $article->articleMetas->firstWhere('meta_key', 'meta_description')?->meta_value
                ?? ''));
        }

        $postType = SeoProjectTask::normalizePostType(
            (string) ($publishBox['post_type'] ?? ArticlePostTypeResolver::resolve($article)),
        );

        $status = (string) ($publishBox['status'] ?? $article->status ?? 'draft');
        if (! in_array($status, ['draft', 'published', 'scheduled', 'private'], true)) {
            $status = 'draft';
        }

        $publishedAt = $article->publishingState?->published_at;
        $tz = SeoDisplayTimezone::name();
        $dt = $publishedAt instanceof Carbon
            ? $publishedAt->copy()->timezone($tz)
            : SeoDisplayTimezone::now();

        return new self(
            title: $title,
            slug: $slug,
            postType: $postType,
            status: $status,
            visibility: ($publishBox['visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            publishDay: (string) ($publishBox['publish_day'] ?? $dt->format('d')),
            publishMonth: (string) ($publishBox['publish_month'] ?? $dt->format('m')),
            publishYear: (string) ($publishBox['publish_year'] ?? $dt->format('Y')),
            publishHour: (string) ($publishBox['publish_hour'] ?? $dt->format('H')),
            publishMinute: (string) ($publishBox['publish_minute'] ?? $dt->format('i')),
            seoMetaDescription: $seoMetaDescription,
            focusKeyword: $focusKeyword,
        );
    }

    public function normalizedSlug(): string
    {
        return Str::slug($this->slug);
    }

    public function resolvePublishAtForSave(): ?Carbon
    {
        if ($this->status === 'draft') {
            return null;
        }

        $candidate = $this->buildPublishAtFromParts();
        if ($candidate instanceof Carbon) {
            return $candidate;
        }

        return SeoDisplayTimezone::now();
    }

    /**
     * @return array{seo_title: string, meta_description: string, focus_keyword: string}
     */
    public function seoPayloadForWordPress(): array
    {
        return [
            'seo_title' => '',
            'meta_description' => $this->seoMetaDescription,
            'focus_keyword' => $this->focusKeyword,
        ];
    }

    private function buildPublishAtFromParts(): ?Carbon
    {
        $year = (int) trim($this->publishYear);
        $month = (int) trim($this->publishMonth);
        $day = (int) trim($this->publishDay);
        $hour = (int) trim($this->publishHour);
        $minute = (int) trim($this->publishMinute);

        if (
            $year < 1970 || $year > 2100
            || $month < 1 || $month > 12
            || $day < 1 || $day > 31
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
        ) {
            return null;
        }

        try {
            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute),
                SeoDisplayTimezone::name(),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
