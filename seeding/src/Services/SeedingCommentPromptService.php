<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\Seeding\Models\SeedingCommentPromptSetting;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptDefaults;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptRenderer;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Throwable;

/**
 * Single editable Gen Comment Manager prompt (no versions, no per-platform prompts).
 */
class SeedingCommentPromptService
{
    public function __construct(
        private readonly ?SeedingCommentPromptRenderer $renderer = null,
    ) {}

    private function renderer(): SeedingCommentPromptRenderer
    {
        return $this->renderer ?? new SeedingCommentPromptRenderer();
    }

    public function getPromptBody(): string
    {
        try {
            $row = SeedingCommentPromptSetting::query()->orderBy('id')->first();
            if ($row instanceof SeedingCommentPromptSetting) {
                $body = trim((string) $row->prompt_body);
                if ($body !== '') {
                    return (string) $row->prompt_body;
                }
            }
        } catch (Throwable) {
            // Missing table / connection — fall back to default.
        }

        return SeedingCommentPromptDefaults::promptBody();
    }

    public function savePromptBody(string $promptBody): string
    {
        $promptBody = $this->normalizeBody($promptBody);

        $row = SeedingCommentPromptSetting::query()->orderBy('id')->first();
        if ($row instanceof SeedingCommentPromptSetting) {
            $row->prompt_body = $promptBody;
            $row->save();
        } else {
            SeedingCommentPromptSetting::query()->create([
                'prompt_body' => $promptBody,
            ]);
        }

        return $promptBody;
    }

    public function renderFinalPrompt(string $managerPrompt, string $mcpContext): string
    {
        return $this->renderer()->render($managerPrompt, $mcpContext);
    }

    public function renderWithLatestPrompt(string $mcpContext): string
    {
        return $this->renderFinalPrompt($this->getPromptBody(), $mcpContext);
    }

    /**
     * @return list<string>
     */
    public function supportedVariables(): array
    {
        return $this->renderer()->supportedVariables();
    }

    private function normalizeBody(string $promptBody): string
    {
        $trimmed = trim($promptBody);
        if ($trimmed === '') {
            return SeedingCommentPromptDefaults::promptBody();
        }

        return $promptBody;
    }

    public function connectionName(): string
    {
        return SeedingServiceConfig::CONNECTION;
    }
}
