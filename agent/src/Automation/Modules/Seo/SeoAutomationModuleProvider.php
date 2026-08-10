<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\Seo;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\ArticleRunSeoAnalysisHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class SeoAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'seo';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            BusinessEventName::SeoAnalysisStarted,
            BusinessEventName::SeoAnalysisCompleted,
            BusinessEventName::SeoAnalysisFailed,
        ] as $enum) {
            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: SeoArticle::class,
                payloadSchema: [
                    'article_id' => ['type' => 'mixed', 'required' => true],
                ],
                description: $enum->value,
                module: 'seo',
            ));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleRunSeoAnalysis->value,
            handlerClass: ArticleRunSeoAnalysisHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [],
            description: 'Wrap existing SEO analysis / audit service.',
            isAsyncSafe: true,
            timeout: 120,
            module: 'seo',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'ai',
            maxAttemptsPerMinute: 20,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
            ],
        ));
    }
}
