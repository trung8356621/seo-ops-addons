<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleNode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationImportExportService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Tests\TestCase;

final class BusinessHookImportExportV3Test extends TestCase
{
    public function test_schema_version_constant_is_three(): void
    {
        self::assertSame(3, AutomationImportExportService::SCHEMA_VERSION);
    }

    public function test_import_rejects_unsupported_schema_version(): void
    {
        $service = app(AutomationImportExportService::class);

        $this->expectException(AutomationException::class);
        $this->expectExceptionMessage('Unsupported schema_version');

        $service->import([
            'schema_version' => 2,
            'rule' => ['code' => 'legacy-rule', 'event_name' => 'article.created'],
        ]);
    }

    public function test_export_payload_redacts_sensitive_settings(): void
    {
        $service = app(AutomationImportExportService::class);

        $rule = new AutomationRule([
            'code' => 'webhook-pipeline',
            'name' => 'Webhook',
            'event_name' => 'article.completed',
            'settings' => [
                'webhook_secret' => 'super-secret',
                'note' => 'ok',
            ],
        ]);
        $rule->setRelation('nodes', collect());
        $rule->setRelation('edges', collect());

        $payload = $service->rulePayload($rule, redact: true);

        $secret = $payload['settings']['webhook_secret'] ?? null;
        self::assertNotSame('super-secret', $secret);
        self::assertTrue(
            $secret === '[redacted]'
            || $secret === '***'
            || (is_string($secret) && str_contains(strtolower($secret), 'redact')),
        );
        self::assertSame('ok', $payload['settings']['note']);
    }

    public function test_action_definition_form_fields_helper(): void
    {
        $definition = new AutomationActionDefinition(
            actionCode: AutomationActionCode::Delay->value,
            handlerClass: 'App\\Example',
            inputRules: [],
            settingsRules: ['seconds' => ['type' => 'integer']],
            description: 'Delay',
            isAsyncSafe: true,
            timeout: 5,
            module: 'automation',
            supportsTest: true,
            fieldMeta: [
                'seconds' => ['label' => 'Delay seconds', 'type' => 'integer'],
            ],
        );

        self::assertTrue($definition->supportsTest);
        self::assertSame('integer', $definition->formFields()['seconds']['type']);
    }

    public function test_list_template_slugs_includes_v3_templates(): void
    {
        $service = app(AutomationImportExportService::class);
        $slugs = $service->listTemplateSlugs();

        self::assertContains('article-complete-wordpress', $slugs);
        self::assertContains('webhook-pipeline', $slugs);
    }

    public function test_template_files_exist_on_disk(): void
    {
        $dir = ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Templates';
        self::assertDirectoryExists($dir);
        self::assertFileExists($dir.'/article-complete-wordpress.json');
        self::assertFileExists($dir.'/webhook-pipeline.json');
    }
}
