<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingAnalysisService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;

/**
 * Read-only semantic Topic analysis for one site. Never applies memberships.
 */
final class AnalyzeSiteTopicGroupingCommand extends Command
{
    protected $signature = 'seo:topics-analyze
        {site_id : Site id to analyze}
        {--dump= : Optional local JSON path for summary (no full keyword dump by default)}';

    protected $description = 'Run Topic grouping ANALYSIS only (proposal). Does not apply / mutate Topics.';

    public function handle(TopicGroupingAnalysisService $analysis): int
    {
        $siteId = (int) $this->argument('site_id');
        if ($siteId <= 0) {
            $this->error('site_id required');

            return self::FAILURE;
        }

        $this->info('Provider: '.TopicGroupingProviderMode::current());
        $topicsBefore = SeoTopic::query()->where('site_id', $siteId)->count();
        $membersBefore = SeoTopicKeyword::query()->where('site_id', $siteId)->count();
        $locksBefore = SeoTopicKeyword::query()->where('site_id', $siteId)->where('is_locked', true)->count();

        $run = $analysis->analyzeSite($siteId);

        $topicsAfter = SeoTopic::query()->where('site_id', $siteId)->count();
        $membersAfter = SeoTopicKeyword::query()->where('site_id', $siteId)->count();
        $locksAfter = SeoTopicKeyword::query()->where('site_id', $siteId)->where('is_locked', true)->count();

        $this->line('run_id: '.$run->id);
        $this->line('status: '.$run->status);
        $this->line('keywords: '.$run->keyword_count);
        $this->line('groups: '.$run->group_count);
        $this->line('unassigned: '.$run->unassigned_count);
        $this->line('low_confidence: '.$run->low_confidence_count);
        $this->line('algorithm: '.($run->algorithm ?? ''));
        $this->line('external_analysis_id: '.($run->external_analysis_id ?? ''));
        $this->line(sprintf(
            'business_safety topics/members/locks: before=%d/%d/%d after=%d/%d/%d',
            $topicsBefore,
            $membersBefore,
            $locksBefore,
            $topicsAfter,
            $membersAfter,
            $locksAfter,
        ));

        if ($run->status !== TopicGroupingRunStatus::PROPOSAL_READY) {
            $this->error(($run->error_code ?? 'failed').': '.($run->error_message ?? ''));

            return self::FAILURE;
        }

        $payload = is_array($run->proposal_payload) ? $run->proposal_payload : [];
        $groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
        usort($groups, static fn (array $a, array $b): int => count($b['members'] ?? []) <=> count($a['members'] ?? []));
        $this->line('largest_groups:');
        foreach (array_slice($groups, 0, 8) as $group) {
            $size = count($group['members'] ?? []);
            $this->line(sprintf('  - [%d] %s', $size, (string) ($group['suggested_label'] ?? '')));
        }

        $dump = $this->option('dump');
        if (is_string($dump) && $dump !== '') {
            $summary = [
                'site_id' => $siteId,
                'run_id' => $run->id,
                'status' => $run->status,
                'keyword_count' => $run->keyword_count,
                'group_count' => $run->group_count,
                'unassigned_count' => $run->unassigned_count,
                'low_confidence_count' => $run->low_confidence_count,
                'algorithm' => $run->algorithm,
                'model' => $run->model,
                'topics_before' => $topicsBefore,
                'topics_after' => $topicsAfter,
                'members_before' => $membersBefore,
                'members_after' => $membersAfter,
                'locks_before' => $locksBefore,
                'locks_after' => $locksAfter,
                'largest_groups' => array_map(static function (array $g): array {
                    return [
                        'label' => $g['suggested_label'] ?? '',
                        'size' => count($g['members'] ?? []),
                        'mean_similarity' => $g['metadata']['mean_similarity'] ?? null,
                        'min_similarity' => $g['metadata']['min_similarity'] ?? null,
                    ];
                }, array_slice($groups, 0, 15)),
            ];
            file_put_contents($dump, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Wrote summary: '.$dump);
        }

        return self::SUCCESS;
    }
}
