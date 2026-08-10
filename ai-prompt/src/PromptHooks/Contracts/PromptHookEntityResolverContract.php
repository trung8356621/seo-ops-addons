<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Contracts;

interface PromptHookEntityResolverContract
{
    /**
     * Entity key đăng ký trong registry (vd: article).
     */
    public function key(): string;

    /**
     * Load entity, authorize, trả normalized context (root = entity key).
     *
     * @return array<string, mixed>
     */
    public function resolveContext(int $entityId): array;
}
