<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

/**
 * Present Site MCP draft for side-by-side human review.
 *
 * AI context exposes topics / resolved contacts only — never URLs.
 */
final class SiteMcpPreview
{
    public function __construct(
        private readonly SiteMcpContextAssembler $assembler = new SiteMcpContextAssembler,
    ) {}

    /**
     * @param  array<string, mixed>|null  $draft
     * @param  list<string>|null  $selectedMainTopics
     * @return array<string, mixed>
     */
    public function present(?array $draft, ?array $selectedMainTopics = null): array
    {
        if ($draft === null || $draft === []) {
            return [
                'has_draft' => false,
                'generated_at' => null,
                'website_type' => '',
                'discovery_strategy' => '',
                'official_exists' => false,
                'official_fields_modified' => false,
                'site' => [],
                'content_context' => [],
                'contact' => [
                    'phones' => [],
                    'emails' => [],
                    'socials' => [],
                ],
                'important_pages' => [],
                'discovery_candidates' => [],
                'keyword_context' => [
                    'main_topics' => [],
                    'main_topic_records' => [],
                    'warnings' => [],
                ],
                'ai_context' => [
                    'main_topics' => [],
                    'warnings' => [],
                ],
                'article_context' => [
                    'text' => '',
                    'unresolved' => [],
                    'has_unresolved' => false,
                ],
                'keyword_preview' => [
                    'text' => '',
                    'unresolved' => [],
                    'has_unresolved' => false,
                    'selected_topics' => [],
                    'use_site_mcp' => true,
                ],
                'counts' => [
                    'post' => 0,
                    'page' => 0,
                    'product' => 0,
                    'product_cat' => 0,
                    'root_product_cat' => 0,
                    'attachment' => 0,
                    'product_categories' => 0,
                    'products' => 0,
                    'service_categories' => 0,
                ],
                'generation' => [],
                'raw_json' => '',
            ];
        }

        $keywordContext = is_array($draft['keyword_context'] ?? null) ? $draft['keyword_context'] : [];
        // Backward compat: migrate primary/supporting → main_topics for old drafts.
        $mainTopics = $this->stringList(
            $keywordContext['main_topics']
            ?? $keywordContext['existing_primary_topics']
            ?? []
        );
        $warnings = $this->stringList($keywordContext['warnings'] ?? []);
        $generation = is_array($draft['generation'] ?? null) ? $draft['generation'] : [];
        $site = is_array($draft['site'] ?? null) ? $draft['site'] : [];
        $counts = is_array($draft['counts'] ?? null) ? $draft['counts'] : [];
        $contact = is_array($draft['contact'] ?? null) ? $draft['contact'] : [];

        $selected = $selectedMainTopics ?? $mainTopics;
        $articleContext = $this->assembler->articleContext($draft);
        $keywordPreview = $this->assembler->keywordContext($draft, $selected);

        $aiContext = [
            'main_topics' => $mainTopics,
            'warnings' => $warnings,
        ];

        return [
            'has_draft' => true,
            'generated_at' => isset($generation['generated_at']) ? (string) $generation['generated_at'] : null,
            'website_type' => (string) ($site['website_type'] ?? ''),
            'discovery_strategy' => (string) ($site['discovery_strategy'] ?? ''),
            'official_exists' => (bool) ($generation['official_site_mcp_exists'] ?? false),
            'official_fields_modified' => (bool) ($generation['official_fields_modified'] ?? false),
            'site' => $site,
            'content_context' => is_array($draft['content_context'] ?? null) ? $draft['content_context'] : [],
            'contact' => [
                'phones' => array_values(is_array($contact['phones'] ?? null) ? $contact['phones'] : []),
                'emails' => array_values(is_array($contact['emails'] ?? $contact['email'] ?? null)
                    ? ($contact['emails'] ?? $contact['email'])
                    : []),
                'socials' => array_values(is_array($contact['socials'] ?? null) ? $contact['socials'] : []),
            ],
            'important_pages' => is_array($draft['important_pages'] ?? null) ? array_values($draft['important_pages']) : [],
            'discovery_candidates' => is_array($draft['discovery_candidates'] ?? null)
                ? array_values($draft['discovery_candidates'])
                : [],
            'keyword_context' => [
                'main_topics' => $mainTopics,
                'main_topic_records' => is_array($keywordContext['main_topic_records'] ?? $keywordContext['primary_topic_records'] ?? null)
                    ? array_values($keywordContext['main_topic_records'] ?? $keywordContext['primary_topic_records'])
                    : [],
                'warnings' => $warnings,
            ],
            'ai_context' => $aiContext,
            'article_context' => $articleContext,
            'keyword_preview' => [
                ...$keywordPreview,
                'selected_topics' => $selected,
                'use_site_mcp' => true,
            ],
            'counts' => [
                'post' => (int) ($counts['post'] ?? 0),
                'page' => (int) ($counts['page'] ?? 0),
                'product' => (int) ($counts['product'] ?? $counts['products'] ?? 0),
                'product_cat' => (int) ($counts['product_cat'] ?? $counts['product_categories'] ?? 0),
                'root_product_cat' => (int) ($counts['root_product_cat'] ?? 0),
                'attachment' => (int) ($counts['attachment'] ?? 0),
                'product_categories' => (int) ($counts['product_cat'] ?? $counts['product_categories'] ?? 0),
                'products' => (int) ($counts['product'] ?? $counts['products'] ?? 0),
                'service_categories' => (int) ($counts['service_categories'] ?? 0),
                'excluded' => is_array($counts['excluded'] ?? null) ? $counts['excluded'] : ['product' => 0],
            ],
            'generation' => $generation,
            'raw_json' => (string) json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $text = trim((string) ($item['keyword'] ?? ''));
            } else {
                $text = trim((string) $item);
            }
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values($out);
    }
}
