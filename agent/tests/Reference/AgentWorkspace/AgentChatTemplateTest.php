<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentChatTemplateRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentChatTemplate;
use PHPUnit\Framework\TestCase;

final class AgentChatTemplateTest extends TestCase
{
    private AgentChatTemplateRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentChatTemplateRegistry;
    }

    public function test_builtin_templates_load(): void
    {
        $templates = $this->registry->all();

        self::assertNotEmpty($templates);
        self::assertContainsOnlyInstancesOf(AgentChatTemplate::class, $templates);
    }

    public function test_create_project_month_maps_to_content_project_create(): void
    {
        $template = $this->registry->get('create_project_month');

        self::assertNotNull($template);
        self::assertSame('content_project.create', $template->skillKey);
    }

    public function test_missing_variables_detected(): void
    {
        $template = $this->registry->get('create_project_month');
        self::assertNotNull($template);

        $missing = $template->missingVariables([]);

        self::assertContains('month', $missing);
        self::assertContains('site_name', $missing);
    }

    public function test_unresolved_placeholders_detected_after_partial_render(): void
    {
        $template = $this->registry->get('create_project_month');
        self::assertNotNull($template);

        $rendered = $template->render(['month' => '2026-08']);

        self::assertTrue($template->hasUnresolvedPlaceholders($rendered));
        self::assertStringContainsString('2026-08', $rendered);
    }

    public function test_template_with_skill_key_does_not_need_ai(): void
    {
        $template = $this->registry->get('create_project_month');

        self::assertNotNull($template);
        self::assertNotNull($template->skillKey);
        self::assertSame('content_project.create', $template->skillKey);
    }
}
