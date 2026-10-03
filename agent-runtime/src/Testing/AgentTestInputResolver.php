<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Testing;

use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;

class AgentTestInputResolver
{
    public function __construct(private readonly TaskTestInputResolver $resolver) {}

    /** @param array<string, mixed> $payload */
    public function resolve(int $siteId, array $payload): TaskTestContext
    {
        if (($payload['input_source'] ?? 'article') === 'raw') {
            return $this->resolver->resolveFromRawInput((string) ($payload['raw_input'] ?? ''));
        }

        return $this->resolver->resolve(
            isset($payload['article_id']) ? (int) $payload['article_id'] : null,
            $this->nullableString($payload['title'] ?? null),
            $this->nullableString($payload['keyword'] ?? null),
            static fn (Builder $query): Builder => $query->where('site_id', $siteId),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
