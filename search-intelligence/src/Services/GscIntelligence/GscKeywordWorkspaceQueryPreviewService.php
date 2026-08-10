<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\AddGscQueriesToKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeSelectedKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;

/**
 * Preview adding unmapped GSC queries to a Keyword workspace.
 * Commit path dispatches ImportKeywordsCommand (+ optional AnalyzeSelectedKeywordsCommand) via CommandBus.
 */
final class GscKeywordWorkspaceQueryPreviewService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function preview(string $workspaceRef, array $candidates): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $display = trim((string) ($candidate['display_query'] ?? $candidate['query'] ?? ''));
            $normalized = trim((string) ($candidate['normalized_query'] ?? ''));
            if ($display === '' && $normalized === '') {
                continue;
            }

            $rows[] = [
                'keyword' => $display !== '' ? $display : $normalized,
                'normalized' => $normalized !== '' ? $normalized : $display,
                'source' => 'gsc_intelligence',
                'gsc_impressions' => (int) ($candidate['impressions'] ?? 0),
            ];
        }

        return [
            'workspace_ref' => $workspaceRef,
            'candidate_count' => count($rows),
            'import_rows' => $rows,
            'preview_command' => PreviewAddGscQueriesToKeywordWorkspaceCommand::class,
            'add_command' => AddGscQueriesToKeywordWorkspaceCommand::class,
            'commit_commands' => [
                ImportKeywordsCommand::class,
                AnalyzeSelectedKeywordsCommand::class,
            ],
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}
