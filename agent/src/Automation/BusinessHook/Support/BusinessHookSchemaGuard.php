<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use App\Support\Automation\AutomationConnection;
use Illuminate\Support\Facades\Schema;

final class BusinessHookSchemaGuard
{
    /** @var list<string> */
    public const REQUIRED_TABLES = [
        'business_events',
        'automation_rules',
        'automation_rule_actions',
        'automation_executions',
        'automation_action_executions',
    ];

    /** @var list<string> */
    public const V2_TABLES = [
        'automation_rule_nodes',
        'automation_rule_edges',
        'automation_node_executions',
    ];

    /** @var list<string> */
    public const V3_TABLES = [
        'automation_rule_versions',
        'automation_rule_version_nodes',
        'automation_rule_version_edges',
        'automation_scheduler_heartbeats',
    ];

    /**
     * @return list<string>
     */
    public static function missingTables(?string $connection = null): array
    {
        $connection ??= AutomationConnection::name();
        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function missingV2Tables(?string $connection = null): array
    {
        $connection ??= AutomationConnection::name();
        $missing = [];
        foreach (self::V2_TABLES as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function missingV3Tables(?string $connection = null): array
    {
        $connection ??= AutomationConnection::name();
        $missing = [];
        foreach (self::V3_TABLES as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function missingV3Columns(?string $connection = null): array
    {
        $connection ??= AutomationConnection::name();
        $schema = Schema::connection($connection);
        $missing = [];

        if ($schema->hasTable('automation_rules')) {
            foreach (['site_id', 'draft_revision', 'published_version_id', 'draft_version_id'] as $column) {
                if (! $schema->hasColumn('automation_rules', $column)) {
                    $missing[] = "automation_rules.{$column}";
                }
            }
        }

        if ($schema->hasTable('automation_executions')
            && ! $schema->hasColumn('automation_executions', 'automation_rule_version_id')) {
            $missing[] = 'automation_executions.automation_rule_version_id';
        }

        return $missing;
    }

    public static function migrateV2Hint(): string
    {
        return 'php artisan automation:migrate --only-v2';
    }

    public static function migrateV3Hint(): string
    {
        return 'php artisan automation:migrate --only-v3';
    }

    public static function migrateHint(): string
    {
        return 'php artisan automation:migrate --only-business-hook';
    }
}
