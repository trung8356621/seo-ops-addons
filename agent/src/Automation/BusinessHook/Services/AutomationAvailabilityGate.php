<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationAvailabilityResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Central availability gate — before execution create / enqueue.
 * Business-disabled states return AutomationAvailabilityResult (no throw).
 */
final class AutomationAvailabilityGate
{
    public function __construct(
        private readonly AutomationActionRegistry $actionRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function checkManual(
        string $actionCode,
        Model $subject,
        User $actor,
        array $input = [],
    ): AutomationAvailabilityResult {
        if (! $this->actionRegistry->has($actionCode)) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::ActionNotRegistered->value,
                __('seo-content-ai::filament.automation.gate.action_not_registered', ['action' => $actionCode]),
                $actionCode,
            );
        }

        $definition = $this->actionRegistry->get($actionCode);

        if (! $definition->supportsManualTrigger || ! $definition->manualEnabled) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::ActionManualDisabled->value,
                __('seo-content-ai::filament.automation.gate.action_manual_disabled', ['action' => $actionCode]),
                $actionCode,
            );
        }

        $permission = $this->checkManualPermission($definition, $subject, $actor);
        if (! $permission->allowed) {
            return $permission;
        }

        $siteId = $this->resolveSiteId($subject, $input);
        $rules = $this->resolveManualRules($actionCode, $siteId);

        if ($rules->isEmpty()) {
            $anyDisabled = $this->hasDisabledRuleForAction($actionCode, $siteId);
            $anyDraft = $this->hasUnpublishedRuleForAction($actionCode, $siteId);

            if ($anyDraft) {
                return AutomationAvailabilityResult::block(
                    BusinessHookErrorCode::RuleNotPublished->value,
                    __('seo-content-ai::filament.automation.gate.rule_not_published', ['action' => $actionCode]),
                    $actionCode,
                );
            }

            if ($anyDisabled) {
                return AutomationAvailabilityResult::block(
                    BusinessHookErrorCode::RuleDisabled->value,
                    __('seo-content-ai::filament.automation.gate.rule_disabled', ['action' => $actionCode]),
                    $actionCode,
                );
            }

            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::RuleNotFound->value,
                __('seo-content-ai::filament.automation.gate.rule_not_found', ['action' => $actionCode]),
                $actionCode,
            );
        }

        if ($rules->count() > 1) {
            // Prefer highest priority (lowest number) — do not block unless product requires singleton.
            $rules = $rules->sortBy([
                ['priority', 'asc'],
                ['id', 'asc'],
            ])->values();
        }

        /** @var AutomationRule $rule */
        $rule = $rules->first();
        $publishCheck = $this->assertPublished($rule, $actionCode);
        if (! $publishCheck->allowed) {
            return $publishCheck;
        }

        $connection = $this->checkActionConnection($actionCode, $subject);
        if (! $connection->allowed) {
            return $connection;
        }

        $active = $this->findActiveManualExecution($actionCode, $subject);
        if ($active instanceof AutomationExecution) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::ExecutionAlreadyActive->value,
                __('seo-content-ai::filament.automation.gate.execution_already_active', ['action' => $actionCode]),
                $actionCode,
                $rule->code,
                (int) $rule->id,
                $rule->published_version_id ? (int) $rule->published_version_id : null,
                [
                    'active_execution_id' => (int) $active->id,
                    'active_execution_uuid' => (string) $active->execution_uuid,
                    'dedupe' => true,
                ],
            );
        }

        return AutomationAvailabilityResult::allow(
            code: 'OK',
            message: __('seo-content-ai::filament.automation.gate.available'),
            actionCode: $actionCode,
            ruleCode: $rule->code,
            ruleId: (int) $rule->id,
            publishedVersionId: $rule->published_version_id ? (int) $rule->published_version_id : null,
            context: ['matched_rule_count' => $rules->count()],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkEvent(
        string $eventName,
        ?Model $subject = null,
        array $payload = [],
    ): AutomationAvailabilityResult {
        $siteId = $this->resolveSiteId($subject, $payload);

        $enabled = AutomationRule::query()
            ->where('event_name', $eventName)
            ->where('is_enabled', true)
            ->where(function ($query) use ($siteId): void {
                $query->whereNull('site_id');
                if ($siteId !== null && $siteId > 0) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->where(function ($query): void {
                $query->where('trigger_type', 'event')->orWhereNull('trigger_type');
            })
            ->count();

        if ($enabled === 0) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::RuleNotFound->value,
                __('seo-content-ai::filament.automation.gate.event_no_rule', ['event' => $eventName]),
                context: [
                    'event_name' => $eventName,
                    'matched_rules' => 0,
                    'automation_match_status' => 'skipped',
                    'automation_skip_code' => BusinessHookErrorCode::RuleNotFound->value,
                ],
            );
        }

        return AutomationAvailabilityResult::allow(
            message: __('seo-content-ai::filament.automation.gate.available'),
            context: [
                'event_name' => $eventName,
                'matched_rules' => $enabled,
                'automation_match_status' => 'matched',
            ],
        );
    }

    public function checkSchedule(AutomationRule $rule, DateTimeInterface $scheduledAt): AutomationAvailabilityResult
    {
        unset($scheduledAt);

        if (! (bool) $rule->is_enabled) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::RuleDisabled->value,
                __('seo-content-ai::filament.automation.gate.rule_disabled', ['action' => $rule->code]),
                ruleCode: $rule->code,
                ruleId: (int) $rule->id,
            );
        }

        return $this->assertPublished($rule, $rule->code);
    }

    public function isActionAvailableForManual(string $actionCode, ?int $siteId = null): bool
    {
        return $this->resolveManualRules($actionCode, $siteId)->isNotEmpty();
    }

    /**
     * @return Collection<int, AutomationRule>
     */
    public function resolveManualRules(string $actionCode, ?int $siteId = null): Collection
    {
        return AutomationRule::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($siteId): void {
                $query->whereNull('site_id');
                if ($siteId !== null && $siteId > 0) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->when(
                Schema::connection(\App\Support\Automation\AutomationConnection::name())->hasColumn('automation_rules', 'allow_manual_trigger'),
                static function ($query): void {
                    $query->where(function ($sub): void {
                        $sub->where('allow_manual_trigger', true)
                            ->orWhereNull('allow_manual_trigger');
                    });
                },
            )
            ->where(function ($query) use ($actionCode): void {
                $query->whereHas('actions', static fn ($q) => $q
                    ->where('action_code', $actionCode)
                    ->where('is_enabled', true))
                    ->orWhereHas('nodes', static fn ($q) => $q->where('action_code', $actionCode));
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (AutomationRule $rule): bool => $this->assertPublished($rule, $actionCode)->allowed)
            ->values();
    }

    private function assertPublished(AutomationRule $rule, string $actionCode): AutomationAvailabilityResult
    {
        $needsPublish = $rule->isGraphMode() || $rule->nodes()->exists();
        if ($needsPublish && ! $rule->published_version_id) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::RuleNotPublished->value,
                __('seo-content-ai::filament.automation.gate.rule_not_published', ['action' => $actionCode]),
                $actionCode,
                $rule->code,
                (int) $rule->id,
            );
        }

        return AutomationAvailabilityResult::allow(
            actionCode: $actionCode,
            ruleCode: $rule->code,
            ruleId: (int) $rule->id,
            publishedVersionId: $rule->published_version_id ? (int) $rule->published_version_id : null,
        );
    }

    private function hasDisabledRuleForAction(string $actionCode, ?int $siteId): bool
    {
        return AutomationRule::query()
            ->where('is_enabled', false)
            ->where(function ($query) use ($siteId): void {
                $query->whereNull('site_id');
                if ($siteId !== null && $siteId > 0) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->where(function ($query) use ($actionCode): void {
                $query->whereHas('actions', static fn ($q) => $q->where('action_code', $actionCode))
                    ->orWhereHas('nodes', static fn ($q) => $q->where('action_code', $actionCode));
            })
            ->exists();
    }

    private function hasUnpublishedRuleForAction(string $actionCode, ?int $siteId): bool
    {
        return AutomationRule::query()
            ->where('is_enabled', true)
            ->whereNull('published_version_id')
            ->where(function ($query) use ($siteId): void {
                $query->whereNull('site_id');
                if ($siteId !== null && $siteId > 0) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->where(function ($query): void {
                $query->where('workflow_mode', 'graph')
                    ->orWhereHas('nodes');
            })
            ->where(function ($query) use ($actionCode): void {
                $query->whereHas('actions', static fn ($q) => $q->where('action_code', $actionCode))
                    ->orWhereHas('nodes', static fn ($q) => $q->where('action_code', $actionCode));
            })
            ->exists();
    }

    private function checkManualPermission(
        AutomationActionDefinition $definition,
        Model $subject,
        User $actor,
    ): AutomationAvailabilityResult {
        unset($actor);
        $permission = (string) ($definition->manualPermission ?? '');

        if ($permission === 'wordpress.sync') {
            if (SeoAccessControl::isContentManager() || ! SeoAccessControl::canSyncArticlesToWordPress()) {
                return AutomationAvailabilityResult::block(
                    BusinessHookErrorCode::PermissionDenied->value,
                    __('seo-content-ai::filament.automation.gate.permission_denied'),
                    $definition->actionCode,
                );
            }
            if ($subject instanceof SeoArticle && ! SeoAccessControl::canAccessArticle($subject)) {
                return AutomationAvailabilityResult::block(
                    BusinessHookErrorCode::TenantMismatch->value,
                    __('seo-content-ai::filament.automation.gate.tenant_mismatch'),
                    $definition->actionCode,
                );
            }

            return AutomationAvailabilityResult::allow(actionCode: $definition->actionCode);
        }

        if ($permission === '' && ! SeoAccessControl::canMutateInSeoPanel()) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::PermissionDenied->value,
                __('seo-content-ai::filament.automation.gate.permission_denied'),
                $definition->actionCode,
            );
        }

        return AutomationAvailabilityResult::allow(actionCode: $definition->actionCode);
    }

    private function checkActionConnection(string $actionCode, Model $subject): AutomationAvailabilityResult
    {
        if ($actionCode !== AutomationActionCode::WordpressArticleSync->value) {
            return AutomationAvailabilityResult::allow(actionCode: $actionCode);
        }

        if (! $subject instanceof SeoArticle) {
            return AutomationAvailabilityResult::allow(actionCode: $actionCode);
        }

        $subject->loadMissing('site');
        $site = $subject->site;
        if (! $site instanceof Site) {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::ConnectionMissing->value,
                __('seo-content-ai::filament.automation.gate.connection_missing', ['action' => $actionCode]),
                $actionCode,
            );
        }

        $site->loadMissing('metas');
        $token = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($token === '') {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::CredentialMissing->value,
                __('seo-content-ai::filament.automation.gate.credential_missing', ['action' => $actionCode]),
                $actionCode,
            );
        }

        $url = trim((string) ($site->url ?? $site->domain ?? ''));
        if ($url === '') {
            return AutomationAvailabilityResult::block(
                BusinessHookErrorCode::ConnectionMissing->value,
                __('seo-content-ai::filament.automation.gate.connection_missing', ['action' => $actionCode]),
                $actionCode,
            );
        }

        return AutomationAvailabilityResult::allow(actionCode: $actionCode);
    }

    private function findActiveManualExecution(string $actionCode, Model $subject): ?AutomationExecution
    {
        return AutomationExecution::query()
            ->where('trigger_type', 'manual')
            ->where('action_code', $actionCode)
            ->whereIn('status', [
                AutomationExecutionStatus::Pending->value,
                AutomationExecutionStatus::Processing->value,
            ])
            ->whereHas('businessEvent', function ($query) use ($subject): void {
                $query->where('subject_type', $subject::class)
                    ->where('subject_id', (int) $subject->getKey());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSiteId(?Model $subject, array $input): ?int
    {
        if ($subject instanceof SeoArticle) {
            return isset($subject->site_id) ? (int) $subject->site_id : null;
        }

        if (isset($input['site_id']) && $input['site_id'] !== '' && $input['site_id'] !== null) {
            return (int) $input['site_id'];
        }

        return null;
    }
}
