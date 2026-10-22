<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;
use Omnichannel\Addons\SocialAutomation\Domain\AccountIdentityKey;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\DispatchPackageGate;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptExecutionDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetInvalidationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Contract/unit coverage for RPA Admin auth roles, Script sync, Widget invalidation.
 * No database — pure domain.
 */
final class RpaAdminAuthAndSyncTest extends TestCase
{
    public function test_admin_and_owner_allowed_staff_rejected(): void
    {
        $gate = new RpaAdminRoleGate;
        self::assertTrue($gate->allows($this->user(User::ROLE_ADMIN)));
        self::assertTrue($gate->allows($this->user(User::ROLE_OWNER)));
        self::assertFalse($gate->allows($this->user(User::ROLE_STAFF)));
        self::assertSame('role_staff_forbidden', $gate->denyReason($this->user(User::ROLE_STAFF)));
    }

    public function test_user_identity_not_trusted_from_request_shape(): void
    {
        // Controllers/middleware strip user_id; gate only accepts User model instance.
        $gate = new RpaAdminRoleGate;
        self::assertFalse($gate->allows(null));
        self::assertSame('unauthenticated', $gate->denyReason(null));

        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Middleware/EnsureRpaAdminUserApi.php');
        self::assertStringContainsString("remove('user_id')", $src);
        $auth = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/RpaAdmin/RpaAdminAuthController.php');
        self::assertStringContainsString("unset(\$payload['user_id']", $auth);
    }

    public function test_script_missing_one_side_is_synced(): void
    {
        $planner = new ScriptSyncPlanner;
        $plan = $planner->plan(
            [['script_id' => 'a', 'package_key' => 'a', 'version' => '1.0.0', 'content_digest' => str_repeat('a', 64)]],
            [['script_id' => 'b', 'package_key' => 'b', 'version' => '1.0.0', 'content_digest' => str_repeat('b', 64)]],
        );
        self::assertCount(1, $plan['push_to_remote']);
        self::assertSame('a', $plan['push_to_remote'][0]['script_id']);
        self::assertCount(1, $plan['pull_to_local']);
        self::assertSame('b', $plan['pull_to_local'][0]['script_id']);
        self::assertSame([], $plan['conflicts']);
    }

    public function test_same_digest_skipped(): void
    {
        $digest = str_repeat('c', 64);
        $row = ['script_id' => 's', 'package_key' => 's', 'version' => '1.0.0', 'content_digest' => $digest];
        $plan = (new ScriptSyncPlanner)->plan([$row], [$row]);
        self::assertSame([], $plan['push_to_remote']);
        self::assertSame([], $plan['pull_to_local']);
        self::assertSame([], $plan['conflicts']);
        self::assertSame('same_digest', $plan['skip'][0]['reason']);
    }

    public function test_divergent_edits_do_not_overwrite(): void
    {
        $plan = (new ScriptSyncPlanner)->plan(
            [['script_id' => 's', 'package_key' => 's', 'version' => '1.0.1', 'content_digest' => str_repeat('1', 64), 'lineage' => []]],
            [['script_id' => 's', 'package_key' => 's', 'version' => '1.0.2', 'content_digest' => str_repeat('2', 64), 'lineage' => []]],
        );
        self::assertSame([], $plan['push_to_remote']);
        self::assertSame([], $plan['pull_to_local']);
        self::assertCount(1, $plan['conflicts']);
        self::assertSame('divergent_edits', $plan['conflicts'][0]['reason']);
    }

    public function test_lineage_allows_fast_forward(): void
    {
        $old = str_repeat('a', 64);
        $new = str_repeat('b', 64);
        $plan = (new ScriptSyncPlanner)->plan(
            [['script_id' => 's', 'package_key' => 's', 'version' => '1.1.0', 'content_digest' => $new, 'lineage' => [$old]]],
            [['script_id' => 's', 'package_key' => 's', 'version' => '1.0.0', 'content_digest' => $old]],
        );
        self::assertCount(1, $plan['push_to_remote']);
        self::assertSame([], $plan['conflicts']);
    }

    public function test_script_execution_change_disables_widgets(): void
    {
        $policy = new WidgetInvalidationPolicy;
        $actions = $policy->widgetsToDisable(
            'fb.post',
            '1.1.0',
            str_repeat('n', 64),
            str_repeat('e', 64),
            '1.0.0',
            str_repeat('o', 64),
            str_repeat('x', 64),
            [
                ['package_key' => 'w1', 'is_enabled' => true, 'script_package_key' => 'fb.post'],
                ['package_key' => 'w2', 'is_enabled' => true, 'script_package_key' => 'other'],
            ],
            true,
        );
        self::assertCount(1, $actions);
        self::assertSame('w1', $actions[0]['package_key']);
        self::assertSame(WidgetInvalidationPolicy::REASON, $actions[0]['reason']);
    }

    public function test_metadata_or_video_only_does_not_disable_widgets(): void
    {
        $base = [
            'script_id' => 's1',
            'variables' => [['key' => 't', 'type' => 'text']],
            'outputs' => [],
            'steps' => [['id' => '1', 'action' => 'CLICK_TARGET']],
            'runtime_requirements' => ['runner_min' => '2.0.0'],
            'assets' => [['path' => 'a.png', 'sha256' => str_repeat('a', 64), 'kind' => 'roi']],
        ];
        $withVideo = $base + [
            'video_demo' => [
                'artifact_ref' => 'v.webm',
                'sha256' => str_repeat('b', 64),
                'mime' => 'video/webm',
                'test_run_id' => 'tr1',
            ],
            'description' => 'new meta',
        ];
        self::assertFalse(ScriptExecutionDigest::executionChanged($base, $withVideo));

        $actions = (new WidgetInvalidationPolicy)->widgetsToDisable(
            's1', '1.0.1', str_repeat('1', 64), ScriptExecutionDigest::fromManifest($withVideo),
            '1.0.0', str_repeat('0', 64), ScriptExecutionDigest::fromManifest($base),
            [['package_key' => 'w1', 'is_enabled' => true, 'script_package_key' => 's1']],
            false,
        );
        self::assertSame([], $actions);
    }

    public function test_workflow_with_disabled_widget_cannot_dispatch(): void
    {
        $gate = new DispatchPackageGate;
        $ok = $gate->assertDispatchable(
            ['package_key' => 'wf1', 'kind' => 'workflow', 'is_enabled' => true],
            [['package_key' => 'w1', 'kind' => 'widget', 'is_enabled' => false]],
        );
        self::assertFalse($ok['ok']);
        self::assertStringContainsString('widget_disabled', $ok['errors'][0]);
    }

    public function test_admin_agent_not_runner_online(): void
    {
        $policy = new AgentKindPolicy;
        self::assertFalse($policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_ADMIN, true));
        self::assertTrue($policy->countsAsOnlineForDispatch(AgentKindPolicy::KIND_RUNNER, true));

        $sel = new EligibilitySelector;
        $result = $sel->select(
            [
                ['agent_id' => 1, 'online' => true, 'agent_kind' => 'admin', 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1],
            ],
            [
                [
                    'session_id' => 10,
                    'agent_id' => 1,
                    'ready_status' => 'ready',
                    'busy' => false,
                    'platform' => 'facebook',
                    'account' => [
                        'platform' => 'facebook',
                        'platform_account_id' => '99',
                        'verified' => true,
                        'identity_source' => 'platform_id',
                    ],
                ],
            ],
            ['platform' => 'facebook', 'target_count' => 1]
        );
        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('admin_not_runner', $result['skip_reasons']);
    }

    public function test_same_domain_is_not_account_identity(): void
    {
        $a = ['platform' => 'facebook', 'domain' => 'facebook.com', 'declared_email' => 'a@x.com'];
        $b = ['platform' => 'facebook', 'domain' => 'facebook.com', 'declared_email' => 'b@x.com'];
        self::assertNull(AccountIdentityKey::strongKey($a));
        self::assertNull(AccountIdentityKey::strongKey($b));
        self::assertFalse(AccountIdentityKey::definitelySame($a, $b));

        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString("payload_forbidden_field", $src);
        self::assertStringContainsString("'cookies'", $src);
        self::assertStringContainsString("'password'", $src);
    }

    public function test_session_sync_contract_rejects_credentials_in_source(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString('session_forbidden_field', $src);
        self::assertStringContainsString('profile_path', $src);
        self::assertStringNotContainsString("\$table->string('cookie", (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_10_21_110000_create_sa_agent_session_account_tables.php'
        ));
    }

    public function test_navigation_registers_scripts_widgets_workflows_clients(): void
    {
        $panel = (string) file_get_contents(dirname(__DIR__, 2).'/src/Providers/SocialAutomationPanelProvider.php');
        self::assertStringContainsString('SocialAutomationScriptsPage', $panel);
        self::assertStringContainsString('SocialAutomationWidgetsPage', $panel);
        self::assertStringContainsString('SocialAutomationWorkflowsPage', $panel);
        $agents = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Pages/SocialAutomationAgentsPage.php');
        self::assertStringContainsString("slug = 'clients'", $agents);
        $packages = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Pages/SocialAutomationPackagesPage.php');
        self::assertStringContainsString('$shouldRegisterNavigation = false', $packages);
        $accounts = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Pages/SocialAutomationAccountsPage.php');
        self::assertStringContainsString('$shouldRegisterNavigation = false', $accounts);
    }

    public function test_rpa_admin_routes_registered_separately_from_runner(): void
    {
        $provider = (string) file_get_contents(dirname(__DIR__, 2).'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('social-automation/rpa-admin', $provider);
        self::assertStringContainsString("post('/login'", $provider);
        self::assertStringContainsString('EnsureRpaAdminUserApi', $provider);
        self::assertStringContainsString('scripts/sync/plan', $provider);
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/api-services.php');
        self::assertStringContainsString('runner:job', $routes);
        self::assertStringNotContainsString('rpa-admin/login', $routes);
    }

    private function user(string $role): User
    {
        $user = new User;
        $user->forceFill([
            'id' => 1,
            'role' => $role,
            'status' => User::STATUS_NORMAL,
            'email' => $role.'@example.test',
            'name' => $role,
            'is_system' => false,
        ]);

        return $user;
    }
}
