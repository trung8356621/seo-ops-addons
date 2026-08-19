<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BlockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestartGenerationWithKeywordCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;

/**
 * Canonical item-action metadata for row menu + bulk Actions menu.
 * Presentation only — does not dispatch CommandBus.
 *
 * @phpstan-type ActionDefinition array{
 *     key: string,
 *     label_key: string,
 *     confirm_key: string|null,
 *     group: string,
 *     icon: string,
 *     single: bool,
 *     bulk: bool,
 *     presenter_flag: string,
 *     bulk_presenter_flag: string,
 *     single_method: string|null,
 *     bulk_method: string|null,
 *     command_family: list<class-string>,
 *     processing_kind: string|null,
 *     destructive: bool,
 *     permission: string
 * }
 */
final class ContentProjectItemActionCatalog
{
    public const GROUP_CONTENT = 'content';

    public const GROUP_REVIEW = 'review';

    public const GROUP_PUBLISHING = 'publishing_queue';

    public const GROUP_LIFECYCLE = 'lifecycle';

    public const GROUP_OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function groupOrder(): array
    {
        return [
            self::GROUP_CONTENT,
            self::GROUP_REVIEW,
            self::GROUP_PUBLISHING,
            self::GROUP_LIFECYCLE,
            self::GROUP_OTHER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groupHeadings(): array
    {
        return [
            self::GROUP_CONTENT => 'Content',
            self::GROUP_REVIEW => 'Review',
            self::GROUP_PUBLISHING => 'Publishing Queue',
            self::GROUP_LIFECYCLE => 'Lifecycle',
            self::GROUP_OTHER => 'Other',
        ];
    }

    /**
     * @return list<ActionDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::def(
                key: 'open_article',
                labelKey: 'seo-content-ai::filament.projects.item_action_open_article',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-arrow-top-right-on-square',
                single: true,
                bulk: false,
                presenterFlag: 'open_article',
                singleMethod: null,
                commandFamily: [],
            ),
            self::def(
                key: 'run_generation',
                labelKey: 'seo-content-ai::filament.projects.item_action_run_generation',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-play',
                single: true,
                bulk: true,
                presenterFlag: 'create_or_rerun',
                bulkPresenterFlag: 'run_generation_bulk',
                singleMethod: 'createOrRerunOne',
                bulkMethod: 'generateSelected',
                commandFamily: [
                    GenerateProjectItemsCommand::class,
                    RerunProjectItemsCommand::class,
                ],
                processingKind: 'generation',
            ),
            self::def(
                key: 'rerun_outline',
                labelKey: 'seo-content-ai::filament.projects.item_action_rerun_outline',
                confirmKey: 'seo-content-ai::filament.projects.item_action_rerun_outline_confirm',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-document-text',
                single: true,
                bulk: true,
                presenterFlag: 'regen_outline',
                singleMethod: 'regenOutline',
                bulkMethod: 'bulkRegenOutline',
                commandFamily: [RerunProjectItemStepCommand::class],
                processingKind: 'generation',
            ),
            self::def(
                key: 'rerun_writing',
                labelKey: 'seo-content-ai::filament.projects.item_action_rerun_writing',
                confirmKey: 'seo-content-ai::filament.projects.item_action_rerun_writing_confirm',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-pencil-square',
                single: true,
                bulk: true,
                presenterFlag: 'regen_article',
                singleMethod: 'regenArticle',
                bulkMethod: 'bulkRegenArticle',
                commandFamily: [RerunProjectItemStepCommand::class],
                processingKind: 'generation',
            ),
            self::def(
                key: 'restart_with_keyword',
                labelKey: 'seo-content-ai::filament.projects.item_action_restart_with_keyword',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-key',
                single: true,
                bulk: false,
                presenterFlag: 'restart_with_keyword',
                singleMethod: 'openRestartWithKeyword',
                commandFamily: [RestartGenerationWithKeywordCommand::class],
                processingKind: 'generation',
            ),
            self::def(
                key: 'skip_generation',
                labelKey: 'seo-content-ai::filament.projects.item_action_skip_generation',
                confirmKey: 'seo-content-ai::filament.projects.item_action_skip_generation_confirm',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-no-symbol',
                single: true,
                bulk: true,
                presenterFlag: 'skip_generation',
                singleMethod: 'skipGenerationOne',
                bulkMethod: 'skipGenerationSelected',
                commandFamily: [BlockProjectItemGenerationCommand::class],
            ),
            self::def(
                key: 'regen_image',
                labelKey: 'seo-content-ai::filament.projects.item_action_regen_image',
                group: self::GROUP_CONTENT,
                icon: 'heroicon-o-photo',
                single: true,
                bulk: false,
                presenterFlag: 'regen_image',
                commandFamily: [],
            ),
            self::def(
                key: 'start_review',
                labelKey: 'seo-content-ai::filament.projects.item_action_start_review',
                group: self::GROUP_REVIEW,
                icon: 'heroicon-o-eye',
                single: true,
                bulk: true,
                presenterFlag: 'start_review',
                singleMethod: 'startReviewOne',
                bulkMethod: 'startReviewSelected',
                commandFamily: [StartReviewCommand::class],
                processingKind: 'other',
            ),
            self::def(
                key: 'approve',
                labelKey: 'seo-content-ai::filament.projects.item_action_approve',
                group: self::GROUP_REVIEW,
                icon: 'heroicon-o-check-badge',
                single: true,
                bulk: true,
                presenterFlag: 'approve',
                singleMethod: 'approveOne',
                bulkMethod: 'approveSelected',
                commandFamily: [ApproveProjectItemsCommand::class],
                processingKind: 'other',
                permission: 'approve',
            ),
            self::def(
                key: 'send_publishing_queue',
                labelKey: 'seo-content-ai::filament.projects.send_to_publishing_queue',
                confirmKey: 'seo-content-ai::filament.projects.send_to_publishing_queue_bulk_confirm',
                group: self::GROUP_PUBLISHING,
                icon: 'heroicon-o-queue-list',
                single: true,
                bulk: true,
                presenterFlag: 'send_to_publishing_queue',
                singleMethod: 'sendToPublishingQueueOne',
                bulkMethod: 'bulkSendToPublishingQueue',
                commandFamily: [SendToPublishingQueueCommand::class],
                processingKind: 'other',
            ),
            self::def(
                key: 'archive',
                labelKey: 'seo-content-ai::filament.projects.item_action_archive',
                confirmKey: 'seo-content-ai::filament.projects.archive_selected_confirm',
                group: self::GROUP_LIFECYCLE,
                icon: 'heroicon-o-archive-box',
                single: true,
                bulk: true,
                presenterFlag: 'archive_item',
                singleMethod: 'archiveOne',
                bulkMethod: 'archiveSelected',
                commandFamily: [ArchiveProjectItemsCommand::class],
                destructive: true,
            ),
            self::def(
                key: 'view_details',
                labelKey: 'seo-content-ai::filament.projects.item_action_view_details',
                group: self::GROUP_OTHER,
                icon: 'heroicon-o-information-circle',
                single: true,
                bulk: false,
                presenterFlag: 'view_details',
                singleMethod: 'openExecutionDetails',
                commandFamily: [],
            ),
        ];
    }

    /**
     * @return list<ActionDefinition>
     */
    public static function bulkDefinitions(): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $def): bool => $def['bulk'] === true,
        ));
    }

    /**
     * @return list<ActionDefinition>
     */
    public static function singleDefinitions(): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $def): bool => $def['single'] === true,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $flagsByTaskId
     * @param  array<string, int>  $eligibleOverrides
     * @return list<array<string, mixed>>
     */
    public static function summarizeBulk(
        int $selectedCount,
        array $flagsByTaskId,
        array $eligibleOverrides = [],
    ): array {
        $items = [];
        foreach (self::bulkDefinitions() as $def) {
            $flag = $def['bulk_presenter_flag'];
            $eligible = $eligibleOverrides[$def['key']] ?? 0;
            if (! array_key_exists($def['key'], $eligibleOverrides)) {
                foreach ($flagsByTaskId as $flags) {
                    if (! empty($flags[$flag])) {
                        $eligible++;
                    }
                }
            }

            $state = 'none';
            if ($selectedCount > 0 && $eligible === $selectedCount) {
                $state = 'all';
            } elseif ($eligible > 0) {
                $state = 'partial';
            }

            $items[] = array_merge($def, [
                'eligible' => $eligible,
                'total' => $selectedCount,
                'state' => $state,
                'enabled' => $state === 'all',
            ]);
        }

        return $items;
    }

    /**
     * @return list<array{key: string, heading: string, actions: list<array<string, mixed>>}>
     */
    public static function groupBulkMenu(array $summaries): array
    {
        $byGroup = [];
        foreach (self::groupOrder() as $group) {
            $byGroup[$group] = [];
        }
        foreach ($summaries as $item) {
            $group = (string) ($item['group'] ?? '');
            if (! isset($byGroup[$group])) {
                continue;
            }
            $byGroup[$group][] = $item;
        }

        $out = [];
        $headings = self::groupHeadings();
        foreach (self::groupOrder() as $group) {
            if ($byGroup[$group] === []) {
                continue;
            }
            $out[] = [
                'key' => $group,
                'heading' => $headings[$group],
                'actions' => $byGroup[$group],
            ];
        }

        return $out;
    }

    public static function definition(string $key): ?array
    {
        foreach (self::definitions() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }

        return null;
    }

    /**
     * @param  list<class-string>  $commandFamily
     * @return ActionDefinition
     */
    private static function def(
        string $key,
        string $labelKey,
        string $group,
        string $icon,
        bool $single,
        bool $bulk,
        string $presenterFlag,
        ?string $singleMethod = null,
        ?string $bulkMethod = null,
        array $commandFamily = [],
        ?string $processingKind = null,
        bool $destructive = false,
        string $permission = 'workflow',
        ?string $confirmKey = null,
        ?string $bulkPresenterFlag = null,
    ): array {
        return [
            'key' => $key,
            'label_key' => $labelKey,
            'confirm_key' => $confirmKey,
            'group' => $group,
            'icon' => $icon,
            'single' => $single,
            'bulk' => $bulk,
            'presenter_flag' => $presenterFlag,
            'bulk_presenter_flag' => $bulkPresenterFlag ?? $presenterFlag,
            'single_method' => $singleMethod,
            'bulk_method' => $bulkMethod,
            'command_family' => $commandFamily,
            'processing_kind' => $processingKind,
            'destructive' => $destructive,
            'permission' => $permission,
        ];
    }
}
