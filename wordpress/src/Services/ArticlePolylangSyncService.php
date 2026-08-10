<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ArticlePolylangSyncService
{
    public const META_TRANSLATION_MAP = 'wp_translation_map';

    /**
     * @param  array<string, mixed>  $item
     */
    public function applyFromSyncItem(SeoArticle $article, Site $site, array $item): void
    {
        $multilingual = $item['multilingual'] ?? null;
        if (! is_array($multilingual)) {
            return;
        }

        $currentLang = trim((string) ($multilingual['current_lang'] ?? ''));
        if ($currentLang !== '') {
            $article->forceFill(['language' => mb_substr($currentLang, 0, 16)])->saveQuietly();
        }

        $translations = $this->normalizeTranslationMap($multilingual['translations'] ?? []);
        if ($translations === []) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_TRANSLATION_MAP],
            ['meta_value' => json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        $groupId = $this->resolveTranslationGroupId($site, $article, $translations);
        if ($groupId <= 0) {
            return;
        }

        $this->bindTranslationGroup($site, $article, $translations, $groupId);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function applyFromSyncPayload(SeoArticle $article, Site $site, array $item): void
    {
        $this->applyFromSyncItem($article, $site, $item);
    }

    /**
     * @return list<array{lang: string, label: string, flag: string, article_id: int|null, wp_post_id: int|null, edit_url: string|null, status: string}>
     */
    public function translationConnectionsForArticle(SeoArticle $article): array
    {
        $article->loadMissing(['site', 'articleMetas']);

        $site = $article->site;
        $currentLang = trim((string) ($article->language ?? 'vi'));
        $translationMap = $this->translationMapFromArticle($article);
        $groupId = (int) ($article->translation_group_id ?? 0);

        $langSlugs = array_values(array_unique(array_filter(array_merge(
            [$currentLang],
            array_keys($translationMap),
            $groupId > 0
                ? SeoArticle::query()
                    ->where('site_id', (int) ($article->site_id ?? 0))
                    ->where('translation_group_id', $groupId)
                    ->pluck('language')
                    ->map(static fn (mixed $lang): string => trim((string) $lang))
                    ->filter(static fn (string $lang): bool => $lang !== '')
                    ->all()
                : [],
        ))));

        if ($langSlugs === []) {
            $langSlugs = ['vi', 'en'];
        }

        $polylang = app(SitePolylangService::class);
        $siteModel = $site instanceof Site ? $site : null;
        $connections = [];

        foreach ($langSlugs as $lang) {
            if ($lang === $currentLang) {
                continue;
            }

            $linkedArticle = $this->findLinkedArticle($article, $lang, $translationMap, $groupId);
            $wpPostId = $linkedArticle instanceof SeoArticle
                ? (int) ($linkedArticle->wordpressLink?->wp_post_id ?? 0)
                : (int) ($translationMap[$lang] ?? 0);

            $connections[] = [
                'lang' => $lang,
                'label' => $polylang->languageLabel($lang, $siteModel),
                'flag' => $polylang->languageFlagEmoji($lang),
                'article_id' => $linkedArticle instanceof SeoArticle ? (int) $linkedArticle->id : null,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'edit_url' => $linkedArticle instanceof SeoArticle
                    ? ArticleResource::getUrl('edit', ['record' => $linkedArticle->id], panel: ArticleResource::panelId())
                    : null,
                'status' => $linkedArticle instanceof SeoArticle ? 'linked' : 'missing',
            ];
        }

        return $connections;
    }

    public function currentLanguageLabel(SeoArticle $article): string
    {
        $article->loadMissing('site');
        $lang = trim((string) ($article->language ?? 'vi'));

        return app(SitePolylangService::class)->languageLabel(
            $lang,
            $article->site instanceof Site ? $article->site : null,
        );
    }

    /**
     * @return array{success: bool, message: string, article_id?: int, edit_url?: string}
     */
    public function importTranslationForLanguage(SeoArticle $sourceArticle, string $targetLang): array
    {
        $sourceArticle->loadMissing(['site', 'articleMetas']);
        $site = $sourceArticle->site;
        if (! $site instanceof Site) {
            return ['success' => false, 'message' => 'Không tìm thấy domain của bài viết.'];
        }

        $targetLang = trim($targetLang);
        if ($targetLang === '') {
            return ['success' => false, 'message' => 'Ngôn ngữ đích không hợp lệ.'];
        }

        $translationMap = $this->translationMapFromArticle($sourceArticle);
        $wpPostId = (int) ($translationMap[$targetLang] ?? 0);
        if ($wpPostId <= 0) {
            return [
                'success' => false,
                'message' => 'WordPress chưa có bản dịch «'.$targetLang.'» cho bài này.',
            ];
        }

        $existing = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->where('type', trim((string) ($sourceArticle->type ?? 'article')))
            ->whereWpPostId($wpPostId)
            ->first();

        if ($existing instanceof SeoArticle) {
            return [
                'success' => true,
                'message' => 'Bản dịch đã tồn tại trên SEO.',
                'article_id' => (int) $existing->id,
                'edit_url' => ArticleResource::getUrl('edit', ['record' => $existing->id], panel: ArticleResource::panelId()),
            ];
        }

        $syncItem = $this->fetchWordPressSyncItem($site, $wpPostId);
        if ($syncItem === null) {
            return ['success' => false, 'message' => 'Không lấy được nội dung bản dịch từ WordPress.'];
        }

        app(SyncDomainContentService::class)->importItems($site, [$syncItem]);

        $imported = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->where('type', trim((string) ($sourceArticle->type ?? 'article')))
            ->whereWpPostId($wpPostId)
            ->first();

        if (! $imported instanceof SeoArticle) {
            return ['success' => false, 'message' => 'Import bản dịch thất bại.'];
        }

        return [
            'success' => true,
            'message' => 'Đã import bản dịch từ WordPress.',
            'article_id' => (int) $imported->id,
            'edit_url' => ArticleResource::getUrl('edit', ['record' => $imported->id], panel: ArticleResource::panelId()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWordPressSyncItem(Site $site, int $wpPostId): ?array
    {
        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return null;
        }

        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($readToken)
                ->get($base.'/wp-json/omi-seo-ai/v1/posts/'.$wpPostId);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return null;
            }

            $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
            if ($post === []) {
                return null;
            }

            $content = (string) ($post['post_content'] ?? '');
            $seo = is_array($post['seo'] ?? null) ? $post['seo'] : [];

            return array_merge($post, [
                'wp_id' => (int) ($post['wp_id'] ?? $wpPostId),
                'type' => (string) ($post['type'] ?? 'article'),
                'scoring' => [
                    'body' => $content,
                    'slug' => (string) ($post['slug'] ?? ''),
                    'seo_title' => (string) ($seo['seo_title'] ?? ''),
                    'meta_description' => (string) ($seo['meta_description'] ?? ''),
                    'focus_keyword' => (string) ($seo['focus_keyword'] ?? ''),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Fetch WordPress translation item failed', [
                'site_id' => $site->id,
                'wp_post_id' => $wpPostId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function normalizeTranslationMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $lang => $entityId) {
            $lang = trim((string) $lang);
            $entityId = (int) $entityId;
            if ($lang === '' || $entityId <= 0) {
                continue;
            }

            $normalized[$lang] = $entityId;
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function resolveTranslationGroupId(Site $site, SeoArticle $article, array $translations): int
    {
        $wpIds = array_values(array_unique(array_filter(array_map('intval', $translations), static fn (int $id): bool => $id > 0)));
        if ($wpIds === []) {
            return (int) ($article->translation_group_id ?? 0);
        }

        $type = trim((string) ($article->type ?? 'article'));

        $existingGroup = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->where('type', $type)
            ->whereWpPostIdIn($wpIds)
            ->whereNotNull('translation_group_id')
            ->value('translation_group_id');

        if ($existingGroup !== null && (int) $existingGroup > 0) {
            return (int) $existingGroup;
        }

        if ((int) ($article->translation_group_id ?? 0) > 0) {
            return (int) $article->translation_group_id;
        }

        $maxGroup = (int) SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->max('translation_group_id');

        if ($maxGroup > 0) {
            return $maxGroup + 1;
        }

        return (int) $article->id;
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function bindTranslationGroup(
        Site $site,
        SeoArticle $article,
        array $translations,
        int $groupId,
    ): void {
        if ($groupId <= 0) {
            return;
        }

        $type = trim((string) ($article->type ?? 'article'));
        $wpIds = array_values(array_unique(array_filter(array_map('intval', $translations), static fn (int $id): bool => $id > 0)));

        SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->where('type', $type)
            ->where(function ($query) use ($wpIds, $article): void {
                $query->whereKey((int) $article->id);
                if ($wpIds !== []) {
                    $query->orWhereIn('wp_post_id', $wpIds);
                }
            })
            ->update(['translation_group_id' => $groupId]);
    }

    /**
     * @return array<string, int>
     */
    private function translationMapFromArticle(SeoArticle $article): array
    {
        $raw = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_TRANSLATION_MAP)?->meta_value ?? ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeTranslationMap($decoded) : [];
    }

    /**
     * @param  array<string, int>  $translationMap
     */
    private function findLinkedArticle(
        SeoArticle $article,
        string $lang,
        array $translationMap,
        int $groupId,
    ): ?SeoArticle {
        $siteId = (int) ($article->site_id ?? 0);
        $type = trim((string) ($article->type ?? 'article'));

        if ($groupId > 0) {
            $fromGroup = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where('type', $type)
                ->where('translation_group_id', $groupId)
                ->where('language', $lang)
                ->whereKeyNot((int) $article->id)
                ->first();

            if ($fromGroup instanceof SeoArticle) {
                return $fromGroup;
            }
        }

        $wpPostId = (int) ($translationMap[$lang] ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->whereWpPostId($wpPostId)
            ->whereKeyNot((int) $article->id)
            ->first();
    }
}
