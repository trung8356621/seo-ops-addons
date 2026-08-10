<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

/**
 * Phase 7 constants — Agent Packs / Skill Studio.
 */
final class AgentPackConstants
{
    public const SCHEMA_VERSION = '1';

    public const WORKSPACE_VERSION = '7.0.0';

    public const SDK_MAJOR = 1;

    public const BUILD_VERSION = '7';

    /** @var list<string> */
    public const TYPES = ['builtin', 'extension', 'custom', 'imported'];

    /** @var list<string> */
    public const TRUST_LEVELS = [
        'builtin',
        'trusted_extension',
        'admin_created',
        'imported_unverified',
    ];

    /** @var list<string> */
    public const STATUSES = [
        'discovered',
        'installed',
        'enabled',
        'disabled',
        'incompatible',
        'unhealthy',
        'quarantined',
        'removed',
    ];

    /** @var list<string> */
    public const REVISION_STATUSES = [
        'draft',
        'validated',
        'active',
        'failed',
        'quarantined',
        'superseded',
    ];

    /** @var list<string> */
    public const CONFIRMATION_RANK = ['none', 'preview', 'confirm', 'destructive'];

    /** @var list<string> */
    public const SAFE_TRANSFORMERS = [
        'trim',
        'lowercase',
        'uppercase',
        'integer',
        'boolean',
        'date_format',
        'unique_array',
        'remove_empty',
    ];

    /** @var list<string> */
    public const INPUT_SOURCE_PREFIXES = [
        '$input.',
        '$context.connection_hash',
        '$context.project_ref',
        '$context.workspace_ref',
        '$context.article_ref',
        '$actor.id',
        '$execution.',
    ];

    /** @var list<string> */
    public const CORE_NAMESPACE_PREFIXES = [
        'agent.',
        'content_project.',
        'operations.',
        'observability.',
        'knowledge.',
        'automation.',
    ];

    /** @var list<string> */
    public const FORBIDDEN_CORE_SKILL_KEYS = [
        'agent.help',
        'agent.new_chat',
    ];

    /** @var list<string> */
    public const PACK_EVENT_TYPES = [
        'pack.discovered',
        'pack.validation_failed',
        'pack.compatibility_failed',
        'pack.compiled',
        'pack.enabled',
        'pack.disabled',
        'pack.revision_activated',
        'pack.rollback',
        'pack.import_rejected',
        'pack.quality_gate_failed',
    ];

    public static function confirmationRank(string $policy): int
    {
        $idx = array_search($policy, self::CONFIRMATION_RANK, true);

        return $idx === false ? -1 : $idx;
    }

    public static function isValidPackKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*)+$/', $key);
    }

    public static function isValidSemver(string $version): bool
    {
        return (bool) preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/',
            $version,
        );
    }

    public static function isCoreSkillKey(string $key): bool
    {
        if (in_array($key, self::FORBIDDEN_CORE_SKILL_KEYS, true)) {
            return true;
        }

        foreach (self::CORE_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix) && ! str_starts_with($key, 'pack.')) {
                // Custom pack skills must use vendor.* or pack.* — reject bare core prefixes.
                $parts = explode('.', $key);
                if (count($parts) >= 2 && ! str_contains($parts[0], '-')) {
                    $first = $parts[0];
                    if (in_array($first, ['agent', 'content_project', 'operations', 'observability', 'knowledge', 'automation'], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
