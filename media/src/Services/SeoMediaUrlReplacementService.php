<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Facades\Log;

/**
 * Safe URL/path replacement for local SEO media references in article body + metas.
 */
final class SeoMediaUrlReplacementService
{
    /**
     * Build old→new map including relative/absolute/encoded path variants.
     *
     * @return array<string, string>
     */
    public function buildVariantMap(string $oldUrl, string $newUrl): array
    {
        $oldUrl = trim($oldUrl);
        $newUrl = trim($newUrl);
        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return [];
        }

        $map = [];
        $this->putPair($map, $oldUrl, $newUrl);

        $oldPath = $this->storagePathFromUrl($oldUrl);
        $newPath = $this->storagePathFromUrl($newUrl);
        if ($oldPath !== '' && $newPath !== '') {
            $this->putPair($map, '/storage/'.$oldPath, '/storage/'.$newPath);
            $this->putPair($map, $oldPath, $newPath);
            $this->putPair($map, rawurlencode($oldPath), rawurlencode($newPath));
            $this->putPair($map, str_replace(' ', '%20', $oldPath), str_replace(' ', '%20', $newPath));
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $urlMap  canonical old_url => new_url
     * @return array<string, string>
     */
    public function expandUrlMap(array $urlMap): array
    {
        $expanded = [];
        foreach ($urlMap as $oldUrl => $newUrl) {
            foreach ($this->buildVariantMap((string) $oldUrl, (string) $newUrl) as $from => $to) {
                $this->putPair($expanded, $from, $to);
            }
        }

        uksort($expanded, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $expanded;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    public function replaceInText(string $text, array $urlMap): string
    {
        if ($text === '' || $urlMap === []) {
            return $text;
        }

        $expanded = $this->expandUrlMap($urlMap);
        foreach ($expanded as $old => $new) {
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }
            $text = str_replace($old, $new, $text);
        }

        // WP sized variants (-1024x768) còn sót sau khi đổi basename.
        foreach ($urlMap as $oldUrl => $newUrl) {
            $text = $this->replaceWordPressUploadStemVariants($text, (string) $oldUrl, (string) $newUrl);
        }

        return $text;
    }

    /**
     * Đổi stem filename trong đường dẫn wp-content/uploads (kèm biến thể -WxH).
     */
    public function replaceWordPressUploadStemVariants(string $text, string $oldUrl, string $newUrl): string
    {
        $oldUrl = trim($oldUrl);
        $newUrl = trim($newUrl);
        if ($text === '' || $oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return $text;
        }

        if (! str_contains($oldUrl, 'wp-content/uploads/') || ! str_contains($newUrl, 'wp-content/uploads/')) {
            return $text;
        }

        $oldStem = $this->uploadFilenameStem($oldUrl);
        $newStem = $this->uploadFilenameStem($newUrl);
        if ($oldStem === '' || $newStem === '' || strcasecmp($oldStem, $newStem) === 0) {
            return $text;
        }

        $pattern = '#(wp-content/uploads/[^\"\'\s<>]*/)'
            .preg_quote($oldStem, '#')
            .'(-\\d+x\\d+)?(\\.(?:jpe?g|png|gif|webp))#i';
        $replaced = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($newStem): string {
                return $matches[1].$newStem.($matches[2] ?? '').$matches[3];
            },
            $text,
        );

        return is_string($replaced) ? $replaced : $text;
    }

    public function uploadFilenameStem(string $url): string
    {
        $filename = $this->uploadFilename($url);
        if ($filename === '') {
            return '';
        }

        $dot = strrpos($filename, '.');
        $stem = $dot === false ? $filename : substr($filename, 0, $dot);
        $stem = (string) preg_replace('/-\d+x\d+$/i', '', $stem);

        return is_string($stem) ? $stem : '';
    }

    private function uploadFilename(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = rawurldecode(trim((string) preg_replace('/[?#].*$/', '', $path)));
        $basename = basename($path);

        return is_string($basename) ? $basename : '';
    }

    /**
     * @param  array<string, string>  $urlMap  canonical old_url => new_url
     * @param  array{editor_session_id?: string|null, user?: \App\Models\User|null, system_publish_preflight?: bool}  $context
     * @return array{article_updated: bool, remaining_old_refs: list<string>}
     */
    public function rewriteArticleReferences(SeoArticle $article, array $urlMap, array $context = []): array
    {
        if ($urlMap === []) {
            return ['article_updated' => false, 'remaining_old_refs' => []];
        }

        $sessionId = isset($context['editor_session_id']) ? (string) $context['editor_session_id'] : null;
        $user = $context['user'] ?? null;

        if (($context['system_publish_preflight'] ?? false) !== true) {
            app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class)
                ->assertBodyRewriteAllowed($article, 'media_url_rewrite', $sessionId, $user instanceof \App\Models\User ? $user : null);
        }

        $article = $article->fresh(['articleMetas']) ?? $article;
        $updated = false;

        $body = (string) ($article->body ?? '');
        $nextBody = $this->replaceInText($body, $urlMap);
        if ($nextBody !== $body) {
            $article->body = $nextBody;
            $updated = true;
            try {
                app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
                    ->invalidateForLegacyBodyWrite($article, 'media_url_rewrite');
            } catch (\Throwable) {
                // best-effort
            }
        }

        $metaKeys = [
            ArticleMediaLocalService::META_FEATURED_URL,
            ArticleMediaLocalService::META_PRODUCT_GALLERY,
        ];

        foreach ($article->articleMetas as $meta) {
            $key = (string) ($meta->meta_key ?? '');
            $raw = (string) ($meta->meta_value ?? '');
            if ($raw === '') {
                continue;
            }

            // Featured/gallery luôn; meta khác chỉ khi chứa URL cũ (tránh rewrite mù).
            $mustCheck = in_array($key, $metaKeys, true);
            if (! $mustCheck) {
                $haystackLower = mb_strtolower($raw);
                $touchesOld = false;
                foreach (array_keys($urlMap) as $oldUrl) {
                    $needle = mb_strtolower((string) $oldUrl);
                    if ($needle !== '' && str_contains($haystackLower, $needle)) {
                        $touchesOld = true;
                        break;
                    }
                }
                if (! $touchesOld) {
                    continue;
                }
            }

            $next = $this->replaceInText($raw, $urlMap);
            if ($next === $raw) {
                continue;
            }

            $meta->meta_value = $next;
            $meta->save();
            $updated = true;
        }

        if ($updated) {
            $article->save();
        }

        $remaining = $this->findRemainingOldRefs(
            (string) (($article->fresh() ?? $article)->body ?? ''),
            $urlMap,
        );

        if ($remaining !== []) {
            Log::warning('seo_media_url_replacement.remaining_old_refs', [
                'article_id' => (int) $article->id,
                'remaining' => $remaining,
            ]);
        }

        return [
            'article_updated' => $updated,
            'remaining_old_refs' => $remaining,
        ];
    }

    /**
     * @param  array<string, string>  $urlMap
     * @return list<string>
     */
    public function findRemainingOldRefs(string $haystack, array $urlMap): array
    {
        if ($haystack === '' || $urlMap === []) {
            return [];
        }

        $remaining = [];
        foreach ($urlMap as $oldUrl => $_) {
            $oldUrl = trim((string) $oldUrl);
            if ($oldUrl === '') {
                continue;
            }

            $path = $this->storagePathFromUrl($oldUrl);
            $needles = array_filter([
                $oldUrl,
                $path !== '' ? '/storage/'.$path : '',
                $path,
            ]);

            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $remaining[] = $oldUrl;
                    break;
                }
            }
        }

        return array_values(array_unique($remaining));
    }

    public function storagePathFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $path = $url;
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = rawurldecode(trim((string) preg_replace('/[?#].*$/', '', $path)));
        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (str_contains($path, 'uploads/seo_media/')) {
            return ltrim($path, '/');
        }

        return '';
    }

    /**
     * @param  array<string, string>  $map
     */
    private function putPair(array &$map, string $from, string $to): void
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        $map[$from] = $to;
    }
}
