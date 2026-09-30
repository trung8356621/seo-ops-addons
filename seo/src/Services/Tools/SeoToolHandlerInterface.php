<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

interface SeoToolHandlerInterface
{
    /**
     * Return the unique tool key this handler is registered for.
     */
    public function getToolKey(): string;

    /**
     * Execute the tool with validated context, input, and confirmation state.
     *
     * @param SeoToolContext $context
     * @param array<string, mixed> $input
     * @param bool $confirmed
     * @return SeoToolExecutionResult
     */
    public function execute(SeoToolContext $context, array $input, bool $confirmed = false): SeoToolExecutionResult;
}
