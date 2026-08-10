<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionBootstrap;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventBootstrap;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\Modules\Sample\SampleAutomationModuleProvider;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry;
use Tests\TestCase;

final class AutomationModuleSdkTest extends TestCase
{
    public function test_default_modules_register_domain_events_and_actions(): void
    {
        $events = new BusinessEventRegistry;
        (new BusinessEventBootstrap)->register($events);

        self::assertTrue($events->has(BusinessEventName::ArticleCompleted->value));
        self::assertTrue($events->has(BusinessEventName::WordpressSynced->value));

        $actions = new AutomationActionRegistry($this->app);
        (new AutomationActionBootstrap)->register($actions);

        self::assertTrue($actions->has(AutomationActionCode::Delay->value));
        self::assertTrue($actions->has(AutomationActionCode::WordpressArticleSync->value));
    }

    public function test_disabled_sample_module_not_loaded_by_default_config(): void
    {
        $events = $this->app->make(BusinessEventRegistry::class);

        self::assertFalse($events->has('sample.ping'));
    }

    public function test_sample_module_registers_when_enabled(): void
    {
        config()->set('seo-content-ai.automation_modules.modules', [
            SampleAutomationModuleProvider::class => true,
        ]);

        $context = AutomationModuleContext::create($this->app);
        (new AutomationModuleRegistry([
            SampleAutomationModuleProvider::class => true,
        ]))->boot($context);

        self::assertTrue($context->events->has('sample.ping'));
        self::assertTrue($context->actions->has('sample.echo'));
        self::assertTrue($context->conditions->hasOperator('sample_starts_with'));

        $engine = new AutomationConditionEngine(
            new AutomationInputMapper,
            $context->conditions,
        );

        self::assertTrue($engine->matches([
            'all' => [[
                'field' => 'payload.message',
                'operator' => 'sample_starts_with',
                'value' => 'he',
            ]],
        ], [
            'event' => [],
            'payload' => ['message' => 'hello'],
            'context' => [],
            'subject' => [],
            'previous' => [],
        ]));
    }

    public function test_core_module_has_no_wordpress_action(): void
    {
        $actions = new AutomationActionRegistry($this->app);
        $context = new AutomationModuleContext(
            events: new BusinessEventRegistry,
            actions: $actions,
            conditions: new AutomationConditionRegistry,
            healthChecks: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry,
            menus: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationMenuRegistry,
            permissions: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationPermissionRegistry,
            settings: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationSettingsRegistry,
            container: $this->app,
        );

        (new \Omnichannel\Addons\Agent\Automation\Modules\Core\CoreAutomationModuleProvider)->register($context);

        self::assertFalse($actions->has(AutomationActionCode::WordpressArticleSync->value));
        self::assertTrue($actions->has(AutomationActionCode::Delay->value));
    }
}
