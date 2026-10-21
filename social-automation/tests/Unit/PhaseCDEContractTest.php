<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests — no database. Integration paths marked PENDING DB MIGRATION.
 */
final class PhaseCDEContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_phase_c_migration_tables(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_10_21_110000_create_sa_agent_session_account_tables.php');
        self::assertStringContainsString("omi_social_automation", $sql);
        self::assertStringContainsString('sa_agents', $sql);
        self::assertStringContainsString('sa_browser_sessions', $sql);
        self::assertStringContainsString('sa_accounts', $sql);
        self::assertStringContainsString('sa_session_accounts', $sql);
        self::assertStringNotContainsString("\$table->string('cookie", $sql);
        self::assertStringNotContainsString("\$table->string('password", $sql);
        self::assertStringNotContainsString('remember_token', $sql);
    }

    public function test_phase_de_migration_tables(): void
    {
        $jobs = (string) file_get_contents($this->root.'/database/migrations/2026_10_21_120000_create_sa_task_job_tables.php');
        self::assertStringContainsString('sa_tasks', $jobs);
        self::assertStringContainsString('sa_schedules', $jobs);
        self::assertStringContainsString('sa_schedule_triggers', $jobs);
        self::assertStringContainsString('idempotency_key', $jobs);
        self::assertStringContainsString('sa_jobs', $jobs);
        self::assertStringContainsString('lease_expires_at', $jobs);

        $art = (string) file_get_contents($this->root.'/database/migrations/2026_10_21_130000_create_sa_artifact_audit_tables.php');
        self::assertStringContainsString('sa_artifacts', $art);
        self::assertStringContainsString('sa_audit_logs', $art);
    }

    public function test_runner_routes_authenticated_and_scoped(): void
    {
        $routes = (string) file_get_contents($this->root.'/routes/api-services.php');
        self::assertStringContainsString('runner/register', $routes);
        self::assertStringContainsString('runner:agent', $routes);
        self::assertStringContainsString('runner:session', $routes);
        self::assertStringContainsString('runner:job', $routes);
        self::assertStringContainsString('admin:dispatch', $routes);
        self::assertStringContainsString('EnsureSocialAutomationServiceApi', $routes);
        self::assertStringNotContainsString('services.service_key', $routes);
    }

    public function test_runner_enrollment_contract_documents_scopes(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Contracts/RunnerEnrollmentContract.php');
        self::assertStringContainsString('social-automation:runner:agent', $src);
        self::assertStringContainsString('Unauthenticated', $src);
        self::assertStringContainsString('service_key', $src);
    }

    public function test_panel_registers_all_nav_pages(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Providers/SocialAutomationPanelProvider.php');
        foreach ([
            'SocialAutomationAgentsPage',
            'SocialAutomationAccountsPage',
            'SocialAutomationSessionsPage',
            'SocialAutomationTasksPage',
            'SocialAutomationSchedulesPage',
            'SocialAutomationJobsPage',
        ] as $page) {
            self::assertStringContainsString($page, $src);
        }
    }

    public function test_integration_dispatch_pending_migration_marker_in_admin_controller(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Http/Controllers/ServiceApi/Admin/TaskAdminController.php');
        self::assertStringContainsString('PENDING DB MIGRATION', $src);
    }
}
