<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use App\Models\Site;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;

/**
 * Thin adapter: canonical Planning Intelligence → discovery prompt context.
 * Does not re-compute coverage; one source of truth.
 */
final class NewContentSuggestionContextBuilder
{
    public function __construct(
        private readonly ContentPlanningIntelligenceService $planning,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *   seed_topic: string,
     *   brief: string,
     *   primary_language: string,
     *   planned_fingerprints: array<string, true>,
     *   rejected_fingerprints: array<string, true>,
     *   existing_keywords: array<string, true>,
     *   covered_keyword_norms: array<string, true>,
     *   context_flags: array<string, bool>,
     *   diagnostics: array<string, mixed>,
     *   planning_preview: array<string, mixed>
     * }
     */
    public function build(SeoProject $project, Site $site, array $options, string $primaryLanguage): array
    {
        $options = NewContentSuggestionOptions::normalize($options);
        $domain = trim((string) ($site->domain ?? ''));
        $focus = $options['focus'];
        $seed = $focus !== '' ? $focus : ($domain !== '' ? $domain : 'new content ideas');

        $ctx = $this->planning->build($project, $site, $options, $primaryLanguage);
        $covered = $ctx['covered_keyword_norms'];

        // Exact title collisions with published content also count as covered.
        foreach ($ctx['existing_topics'] as $topic) {
            $norm = NewContentSuggestionIdentity::normalize((string) ($topic['title'] ?? ''));
            if ($norm !== '') {
                $covered[$norm] = true;
            }
        }

        $mcpUsed = $ctx['mcp_signals'] !== [] || ($ctx['mcp_period'] ?? null) !== null;

        return [
            'seed_topic' => $seed,
            'brief' => $this->planning->renderBrief($ctx, $options),
            'primary_language' => $primaryLanguage,
            'planned_fingerprints' => $ctx['planned_fingerprints'],
            'rejected_fingerprints' => $ctx['rejected_fingerprints'],
            // Backward-compatible key: now means "covered content norms", not KI inventory.
            'existing_keywords' => $covered,
            'covered_keyword_norms' => $covered,
            'context_flags' => [
                'use_site_context' => $options['use_site_context'],
                'use_keyword_intelligence' => $options['use_keyword_intelligence'],
                'use_mcp_context' => $mcpUsed && $options['use_mcp_context'],
            ],
            'diagnostics' => $ctx['diagnostics'],
            'planning_preview' => [
                'principal_keywords_count' => $ctx['diagnostics']['principal_keywords_count'],
                'cluster_count' => $ctx['diagnostics']['cluster_count'],
                'missing_direction_count' => $ctx['diagnostics']['missing_direction_count'],
                'mcp_period' => $ctx['diagnostics']['mcp_period'],
                'top_missing' => array_slice(array_map(
                    static fn (array $row): string => (string) ($row['topic'] ?? ''),
                    $ctx['missing_directions'],
                ), 0, 5),
            ],
        ];
    }
}
