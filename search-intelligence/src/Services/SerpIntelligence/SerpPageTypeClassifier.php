<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpPageType;

/**
 * Phân loại page type từ URL + title + snippet + schema signals.
 */
final class SerpPageTypeClassifier
{
    public const PAGE_TYPE_CLASSIFIER_VERSION = '1.0.0';

    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $signals  url, title, snippet, schema_types?
     * @return array{
     *   page_type: SerpPageType,
     *   confidence: float,
     *   signals: list<string>,
     *   classifier_version: string
     * }
     */
    public function classify(array $signals): array
    {
        $url = (string) ($signals['url'] ?? '');
        $title = mb_strtolower((string) ($signals['title'] ?? ''), 'UTF-8');
        $snippet = mb_strtolower((string) ($signals['snippet'] ?? ''), 'UTF-8');
        $schemaTypes = $this->normalizeSchemaTypes($signals['schema_types'] ?? []);
        $normalized = $url !== '' ? $this->urlNormalizer->normalize($url) : ['normalized_url' => '', 'path' => ''];
        $path = parse_url((string) ($normalized['normalized_url'] ?? ''), PHP_URL_PATH) ?? '/';
        $pathLower = mb_strtolower((string) $path, 'UTF-8');
        $text = trim($title.' '.$snippet.' '.$pathLower);

        $scores = [];
        $hitSignals = [];

        $this->scorePattern($scores, $hitSignals, SerpPageType::Homepage, $this->isHomepage($pathLower), 0.9, 'path_root');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Product, $this->matchesAny($text, ['product', 'san-pham', '/p/']), 0.75, 'product_signal');
        $this->scorePattern($scores, $hitSignals, SerpPageType::ProductListing, $this->matchesAny($text, ['category', 'collection', 'shop', 'cua-hang', '/c/']), 0.7, 'listing_signal');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Category, $this->matchesAny($pathLower, ['/category/', '/danh-muc/', '/tag/']), 0.72, 'category_path');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Comparison, $this->matchesAny($text, ['vs', 'compare', 'so sanh', 'top ', 'best ']), 0.68, 'comparison_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Review, $this->matchesAny($text, ['review', 'danh gia', 'rating']), 0.68, 'review_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Forum, $this->matchesAny($text, ['forum', 'reddit', 'quora', 'voz']), 0.8, 'forum_domain_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Discussion, $this->matchesAny($text, ['discussion', 'thread', 'comment']), 0.65, 'discussion_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Video, $this->matchesAny($text, ['youtube', 'video', '/watch']), 0.82, 'video_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::News, $this->matchesAny($text, ['news', 'tin tuc', 'bao ']), 0.75, 'news_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Tool, $this->matchesAny($text, ['calculator', 'tool', 'generator', 'checker']), 0.7, 'tool_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Documentation, $this->matchesAny($text, ['docs', 'documentation', 'api reference', 'huong dan']), 0.72, 'docs_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::LocalLanding, $this->matchesAny($text, ['near me', 'tai ', 'tp.', 'quan ', 'district']), 0.66, 'local_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Service, $this->matchesAny($text, ['service', 'dich vu', 'pricing', 'packages']), 0.64, 'service_terms');
        $this->scorePattern($scores, $hitSignals, SerpPageType::LandingPage, $this->matchesAny($pathLower, ['/lp/', '/landing/', '/campaign/']), 0.78, 'landing_path');
        $this->scorePattern($scores, $hitSignals, SerpPageType::Article, $this->matchesAny($text, ['blog', 'article', 'post', 'guide', 'how to', 'how-to']), 0.62, 'article_terms');

        foreach ($schemaTypes as $schemaType) {
            $schemaPageType = $this->schemaToPageType($schemaType);
            if ($schemaPageType !== null) {
                $this->scorePattern($scores, $hitSignals, $schemaPageType, true, 0.85, 'schema:'.$schemaType);
            }
        }

        if ($scores === []) {
            return [
                'page_type' => SerpPageType::Unknown,
                'confidence' => 0.25,
                'signals' => ['insufficient_signals'],
                'classifier_version' => self::PAGE_TYPE_CLASSIFIER_VERSION,
            ];
        }

        arsort($scores);
        $bestKey = (string) array_key_first($scores);
        $bestType = SerpPageType::tryFrom($bestKey) ?? SerpPageType::Unknown;
        $bestScore = (float) ($scores[$bestKey] ?? 0.0);

        return [
            'page_type' => $bestType,
            'confidence' => min(0.98, max(0.3, $bestScore)),
            'signals' => array_values(array_unique($hitSignals)),
            'classifier_version' => self::PAGE_TYPE_CLASSIFIER_VERSION,
        ];
    }

    /**
     * @param  list<mixed>  $schemaTypes
     * @return list<string>
     */
    private function normalizeSchemaTypes(array $schemaTypes): array
    {
        $normalized = [];
        foreach ($schemaTypes as $type) {
            if (is_string($type) && trim($type) !== '') {
                $normalized[] = mb_strtolower(trim($type), 'UTF-8');
            }
        }

        return $normalized;
    }

    private function isHomepage(string $path): bool
    {
        return $path === '/' || $path === '';
    }

    /**
     * @param  array<string, float>  $scores
     * @param  list<string>  $hitSignals
     */
    private function scorePattern(array &$scores, array &$hitSignals, SerpPageType $type, bool $matched, float $weight, string $signal): void
    {
        if (! $matched) {
            return;
        }

        $scores[$type->value] = max($scores[$type->value] ?? 0.0, $weight);
        $hitSignals[] = $signal;
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    private function schemaToPageType(string $schemaType): ?SerpPageType
    {
        return match (true) {
            str_contains($schemaType, 'product') => SerpPageType::Product,
            str_contains($schemaType, 'article'), str_contains($schemaType, 'blogposting'), str_contains($schemaType, 'newsarticle') => SerpPageType::Article,
            str_contains($schemaType, 'faq') => SerpPageType::Article,
            str_contains($schemaType, 'video') => SerpPageType::Video,
            str_contains($schemaType, 'localbusiness') => SerpPageType::LocalLanding,
            str_contains($schemaType, 'service') => SerpPageType::Service,
            str_contains($schemaType, 'webpage') => SerpPageType::LandingPage,
            default => null,
        };
    }
}
