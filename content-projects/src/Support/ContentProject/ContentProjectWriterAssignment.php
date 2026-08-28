<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use App\Services\Users\SeoOpsSystemUser;

/**
 * seo_projects.user_id = assigned writer (not technical creator).
 * System user id is a FK placeholder only — treated as unassigned for gates.
 * List display: real writer name; legacy empty/system rows show "—" (no "Chưa phân công" UX).
 */
final class ContentProjectWriterAssignment
{
    public static function hasRealWriter(SeoProject $project): bool
    {
        $userId = (int) ($project->user_id ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return ! SeoOpsSystemUser::isSystemUserId($userId);
    }

    public static function isUnassigned(SeoProject $project): bool
    {
        return ! self::hasRealWriter($project);
    }

    public static function displayLabel(SeoProject $project): string
    {
        if (! self::hasRealWriter($project)) {
            return '—';
        }

        $name = trim((string) ($project->user?->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return '#'.(int) $project->user_id;
    }
}
