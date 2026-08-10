<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Batch D — seo_projects.status classification.
 *
 * Final wording: **non-authoritative for item lifecycle**.
 *
 * Item phase / counters / MCP item state = ContentProjectItemStateResolver only.
 * seo_projects.status remains a **project-level workflow flag** (approval/list heuristics).
 * Column retained. Not “decorative” — still drives some project-level behavior.
 *
 * Consumer classes:
 * A = authoritative project-level workflow behavior (not item lifecycle)
 * B = compatibility / display only
 * C = legacy heuristic (should not drive item lifecycle; migrate later)
 */
final class ContentProjectStatusDecision
{
    public const MODE = 'project_level_flag_non_authoritative_for_items';

    /**
     * @return array<string, 'A'|'B'|'C'>
     */
    public static function consumerClass(): array
    {
        return [
            // A — project-level approval / create / edit flag
            'ApproveProjectItemsHandler' => 'A', // stamps STATUS_APPROVED after item approve
            'CreateContentProjectHandler' => 'A', // initial project status
            'EditSeoProject' => 'A', // preserves APPROVED vs MANUAL on save

            // B — display / widgets / archive gate does not use status
            'SeoProjectResource badges' => 'B',
            'SeoOverviewStats' => 'B', // counts STATUS_RUNNING projects
            'WpSyncStatusTable' => 'B',
            'ArchiveContentProjectService' => 'B', // gates on archived_at / run / queue — not project.status

            // C — legacy filters / writers still reading status
            'KeywordResource active-project filter' => 'C',
            'ArticleResource create STATUS_MANUAL' => 'C',
            'CreateSeoProject' => 'C',
            'SeoProjectArchiveService archiveProject' => 'C', // writes STATUS_MANUAL on archiveProject (not restore/unarchiveItem)
            'SeoProjectTaskMoveService' => 'C',
        ];
    }

    public static function isAuthoritativeForItems(): bool
    {
        return false;
    }
}
