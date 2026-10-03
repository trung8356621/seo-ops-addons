<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Testing;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;

class AgentTestInputResolver
{
    public function __construct(
        private readonly TaskTestInputResolver $resolver,
        private readonly SiteDomainPromptContextService $siteContext,
    ) {}

    /** @param array<string, mixed> $payload */
    public function resolve(int $siteId, array $payload): TaskTestContext
    {
        if (($payload['input_source'] ?? 'article') === 'raw') {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                throw new InvalidArgumentException('Selected site was not found.');
            }

            return self::mergeRawSiteContext(
                $this->resolver->resolveFromRawInput((string) ($payload['raw_input'] ?? '')),
                $site,
                $this->siteContext,
            );
        }

        return $this->resolver->resolve(
            isset($payload['article_id']) ? (int) $payload['article_id'] : null,
            $this->nullableString($payload['title'] ?? null),
            $this->nullableString($payload['keyword'] ?? null),
            static fn (Builder $query): Builder => $query->where('site_id', $siteId),
        );
    }

    public static function mergeRawSiteContext(
        TaskTestContext $context,
        Site $site,
        SiteDomainPromptContextService $siteContext,
    ): TaskTestContext
    {
        return $context
            ->withVariables(array_merge($context->variables, $siteContext->promptVariablesForSite($site)))
            ->withSiteId((int) $site->getKey());
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
