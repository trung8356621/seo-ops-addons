<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\ManualAutomationDispatchResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationAvailabilityGate;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\ManualAutomationDispatcher;
use PHPUnit\Framework\TestCase;

final class AutomationAvailabilityGateTest extends TestCase
{
    public function test_gate_and_dispatch_result_contracts_exist(): void
    {
        $base = ProjectRoot::addonsPath().'/agent/src';
        self::assertFileExists($base.'/Automation/BusinessHook/Services/AutomationAvailabilityGate.php');
        self::assertFileExists($base.'/Automation/BusinessHook/Data/AutomationAvailabilityResult.php');
        self::assertFileExists($base.'/Automation/BusinessHook/Data/ManualAutomationDispatchResult.php');
        self::assertTrue(class_exists(AutomationAvailabilityGate::class));
        self::assertTrue(class_exists(ManualAutomationDispatcher::class));
        self::assertTrue(class_exists(ManualAutomationDispatchResult::class));
    }

    public function test_error_codes_cover_availability_matrix(): void
    {
        self::assertSame('AUTOMATION_RULE_NOT_FOUND', BusinessHookErrorCode::RuleNotFound->value);
        self::assertSame('AUTOMATION_RULE_DISABLED', BusinessHookErrorCode::RuleDisabled->value);
        self::assertSame('AUTOMATION_RULE_NOT_PUBLISHED', BusinessHookErrorCode::RuleNotPublished->value);
        self::assertSame('AUTOMATION_CREDENTIAL_MISSING', BusinessHookErrorCode::CredentialMissing->value);
        self::assertSame('AUTOMATION_EXECUTION_ALREADY_ACTIVE', BusinessHookErrorCode::ExecutionAlreadyActive->value);
        self::assertSame('AUTOMATION_ACTION_MANUAL_DISABLED', BusinessHookErrorCode::ActionManualDisabled->value);
    }

    public function test_manual_dispatcher_uses_gate_before_create(): void
    {
        $base = ProjectRoot::addonsPath().'/agent/src';
        $source = (string) file_get_contents($base.'/Automation/BusinessHook/Services/ManualAutomationDispatcher.php');
        self::assertStringContainsString('availabilityGate->checkManual', $source);
        self::assertStringContainsString('ManualAutomationDispatchResult', $source);
        self::assertStringContainsString('ManualAutomationDispatchResult::blocked', $source);
        $createPos = strpos($source, 'AutomationExecution::query()->create');
        $gatePos = strpos($source, 'checkManual');
        self::assertNotFalse($createPos);
        self::assertNotFalse($gatePos);
        self::assertLessThan($createPos, $gatePos);
    }

    public function test_wordpress_manual_service_queues_without_automation_gate(): void
    {
        $base = ProjectRoot::addonsPath().'/wordpress/src';
        $source = (string) file_get_contents($base.'/Services/WordPressManualSyncService.php');
        self::assertStringContainsString('ManualWordPressSyncJob', $source);
        self::assertStringContainsString('ManualSyncContext', $source);
        self::assertStringNotContainsString('ManualAutomationDispatcher', $source);
        self::assertStringNotContainsString('AutomationAvailabilityGate', $source);
        self::assertStringNotContainsString('AutomationException', $source);
    }

    public function test_controller_maps_blocked_to_danger_toast(): void
    {
        $base = ProjectRoot::addonsPath().'/content/src';
        $source = (string) file_get_contents($base.'/Http/Controllers/ArticleEditorSyncController.php');
        self::assertStringContainsString('wp_sync_blocked_title', $source);
        self::assertStringContainsString('dispatched_title', $source);
        self::assertStringContainsString('deduplicated_title', $source);
        self::assertStringContainsString("'danger'", $source);
    }

    public function test_event_dispatcher_records_skip_metadata(): void
    {
        $base = ProjectRoot::addonsPath().'/agent/src';
        $source = (string) file_get_contents($base.'/Automation/BusinessHook/Services/BusinessEventDispatcher.php');
        self::assertStringContainsString('automation_match_status', $source);
        self::assertStringContainsString('automation_skip_code', $source);
        self::assertStringContainsString('no_enabled_rule', $source);
    }

    public function test_list_rules_banner_uses_gate(): void
    {
        $base = ProjectRoot::addonsPath().'/agent/src';
        $source = (string) file_get_contents(
            $base.'/Filament/Resources/AutomationRuleResource/Pages/ListAutomationRules.php'
        );
        self::assertStringContainsString('AutomationAvailabilityGate', $source);
        self::assertStringNotContainsString('WordPressAutomationAvailability', $source);
    }
}
