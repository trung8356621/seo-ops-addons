<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Canonical Agent Workspace v1.0 version metadata — single source of truth.
 */
final class AgentWorkspaceVersion
{
    public const VERSION = '1.0.0';

    public const PACK_SCHEMA = '1.0';

    public const SKILL_SCHEMA = '1.0';

    public const PLANNING_SCHEMA = '1.0';

    public const EXECUTION_SCHEMA = '1.0';

    public const KNOWLEDGE_SCHEMA = '1.0';

    public const AUTOMATION_SCHEMA = '1.0';

    public const OBSERVABILITY_SCHEMA = '1.0';

    public const EVALUATION_SCHEMA = '1.0';

    public const INVENTORY_SCHEMA = '1.0';

    /**
     * @return array<string, string>
     */
    public static function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'pack_schema' => self::PACK_SCHEMA,
            'skill_schema' => self::SKILL_SCHEMA,
            'planning_schema' => self::PLANNING_SCHEMA,
            'execution_schema' => self::EXECUTION_SCHEMA,
            'knowledge_schema' => self::KNOWLEDGE_SCHEMA,
            'automation_schema' => self::AUTOMATION_SCHEMA,
            'observability_schema' => self::OBSERVABILITY_SCHEMA,
            'evaluation_schema' => self::EVALUATION_SCHEMA,
            'inventory_schema' => self::INVENTORY_SCHEMA,
            'freeze' => 'v1.0',
        ];
    }
}
