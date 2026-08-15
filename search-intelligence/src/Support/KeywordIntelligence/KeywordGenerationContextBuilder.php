<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordGenerationContextBuilder
{
    public const DEFAULT_MAX_TOPICS = 50;

    public const DEFAULT_MAX_EXCLUSIONS = 150;

    public const DEFAULT_MAX_REPRESENTATIVES = 5;

    /**
     * @param  array<string, mixed>  $landscape
     * @param  array{max_topics?: int, max_exclusions?: int, max_representatives?: int, site?: string}  $options
     * @return array<string, mixed>
     */
    public function build(array $landscape, array $options = []): array
    {
        $maxTopics = max(10, min(80, (int) ($options['max_topics'] ?? self::DEFAULT_MAX_TOPICS)));
        $maxExclusions = max(20, min(300, (int) ($options['max_exclusions'] ?? self::DEFAULT_MAX_EXCLUSIONS)));
        $maxReps = max(1, min(8, (int) ($options['max_representatives'] ?? self::DEFAULT_MAX_REPRESENTATIVES)));

        /** @var list<array<string, mixed>> $clusters */
        $clusters = is_array($landscape['clusters'] ?? null) ? $landscape['clusters'] : [];
        $core = [];
        $saturated = [];
        $weak = [];
        $missingDirections = [];
        $intentGaps = [];
        $exclusions = [];
        $contentGaps = [];

        foreach ($clusters as $cluster) {
            $coverage = (string) ($cluster['coverage'] ?? 'unknown');
            $primary = (string) ($cluster['primary'] ?? $cluster['cluster'] ?? '');
            if ($primary === '') {
                continue;
            }
            $topic = [
                'topic' => $primary,
                'coverage' => $coverage,
                'usable' => (int) ($cluster['usable_keyword_count'] ?? 0),
                'intents' => $cluster['intent_coverage'] ?? [],
            ];
            if ($coverage === 'saturated') {
                $saturated[] = $topic;
            } elseif ($coverage === 'weak') {
                $weak[] = $topic;
            } elseif ($coverage === 'missing') {
                $weak[] = $topic;
            } elseif ($coverage === 'healthy') {
                $core[] = $topic;
            }

            foreach ((array) ($cluster['missing_directions'] ?? []) as $dir) {
                $missingDirections[] = [
                    'cluster' => $primary,
                    'direction' => (string) $dir,
                ];
            }
            foreach ((array) ($cluster['intent_gaps'] ?? []) as $gap) {
                $intentGaps[] = [
                    'cluster' => $primary,
                    'missing_intent' => (string) $gap,
                ];
            }
            if (in_array($coverage, ['missing', 'weak'], true)) {
                $contentGaps[] = [
                    'cluster' => $primary,
                    'target_pages' => (int) ($cluster['target_pages'] ?? 0),
                    'published' => (int) ($cluster['published'] ?? 0),
                    'planned' => (int) ($cluster['planned'] ?? 0),
                    'coverage' => $coverage,
                ];
            }

            $exclusions[] = $primary;
            foreach (array_slice((array) ($cluster['representative_variants'] ?? []), 0, $maxReps) as $variant) {
                $exclusions[] = (string) $variant;
            }
        }

        $topicsBudget = $maxTopics;
        $core = array_slice($core, 0, (int) floor($topicsBudget * 0.4));
        $saturated = array_slice($saturated, 0, (int) floor($topicsBudget * 0.25));
        $weak = array_slice($weak, 0, $topicsBudget - count($core) - count($saturated));
        $missingDirections = array_slice($missingDirections, 0, 40);
        $intentGaps = array_slice($intentGaps, 0, 40);
        $contentGaps = array_slice($contentGaps, 0, 30);
        $exclusions = array_slice(array_values(array_unique(array_filter($exclusions))), 0, $maxExclusions);

        return [
            'site' => (string) ($options['site'] ?? ''),
            'core_topics' => $core,
            'saturated_topics' => $saturated,
            'weak_topics' => $weak,
            'missing_directions' => $missingDirections,
            'intent_gaps' => $intentGaps,
            'existing_canonicals' => $exclusions,
            'exclude_patterns' => $exclusions,
            'content_gaps' => $contentGaps,
            'generation_rules' => [
                'Generate only new SEO opportunities for weak/missing topics or intents.',
                'Do not generate paraphrases of existing canonical keywords.',
                'Do not expand saturated clusters.',
                'Avoid full sentences, descriptive marketing anchors, brand copy, and URL/domain strings.',
                'Do not use Loại SEO / excluded phrases as existing topics except as negative patterns.',
                'Avoid near duplicates of existing_canonicals.',
            ],
            'budget' => [
                'max_topics' => $maxTopics,
                'max_exclusions' => $maxExclusions,
                'topics_sent' => count($core) + count($saturated) + count($weak),
                'exclusions_sent' => count($exclusions),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function toPromptBlock(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return "Compact keyword landscape (do not treat as a full keyword dump):\n".$json;
    }
}
