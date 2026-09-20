<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Idempotent default Prompt + Settings binding for seeding.comment.generate.
 *
 * Prefer existing omi_seeding.seeding_comment_prompt_settings body when present
 * so operator customizations become the initial shared Prompt version.
 * Does not overwrite an existing Settings binding or an already-created named Prompt.
 */
final class DefaultSeedingCommentPromptInstaller
{
    public const HOOK_KEY = 'seeding.comment.generate';

    public const HOOK_VERSION = '0.1.0';

    public const PROMPT_NAME = 'Generate seeding social comments (default)';

    public const DEFAULT_MARKDOWN = <<<'MD'
You generate short, natural Vietnamese social comments based only on the supplied text context.

Requirements:
- Write in Vietnamese.
- Match the supplied context.
- Adapt naturally to the supplied social platform.
- Do not invent unsupported facts.
- Use varied wording and sentence structure.
- Avoid obvious repeated patterns.
- Avoid sounding like spam or forced advertising.
- Keep each comment concise.
- Each comment must be under 300 words.

{{mcp_context}}
MD;

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{prompt_id: int, created: bool, binding_set: bool, source: string}
     */
    public function install(): array
    {
        $existing = SeoPrompt::query()
            ->where('hook_key', self::HOOK_KEY)
            ->where('name', self::PROMPT_NAME)
            ->orderBy('id')
            ->first();

        $created = false;
        $source = 'existing_prompt';

        if ($existing === null) {
            [$markdown, $source] = $this->resolveInitialMarkdown();
            $connectionId = $this->defaultAiConnectionId();
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => $markdown,
                'hook_key' => self::HOOK_KEY,
                'hook_version' => self::HOOK_VERSION,
                'variables' => [
                    ['name' => 'mcp_context', 'description' => 'Seeding MCP / domain context text'],
                    ['name' => 'social', 'description' => 'Social platform id (threads, facebook, …)'],
                    ['name' => 'quantity', 'description' => 'Requested comment count (1–12)'],
                ],
                'ai_connection_id' => $connectionId,
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                    'bootstrap_source' => $source,
                ],
            ]);
            $existing->save();
            $created = true;
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[self::HOOK_KEY]) || (int) $bindings[self::HOOK_KEY] !== $promptId) {
            if (! isset($bindings[self::HOOK_KEY])) {
                $this->settings->savePromptHookBindings([
                    self::HOOK_KEY => $promptId,
                ]);
                $bindingSet = true;
            }
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
            'source' => $source,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveInitialMarkdown(): array
    {
        try {
            $row = DB::connection('omi_seeding')
                ->table('seeding_comment_prompt_settings')
                ->orderBy('id')
                ->first();
            if ($row !== null) {
                $body = (string) ($row->prompt_body ?? '');
                if (trim($body) !== '') {
                    return [$body, 'omi_seeding.seeding_comment_prompt_settings'];
                }
            }
        } catch (\Throwable) {
            // Seeding DB may be absent in some install planes — use default.
        }

        return [self::DEFAULT_MARKDOWN, 'default_markdown'];
    }

    private function defaultAiConnectionId(): ?int
    {
        try {
            $id = ApiConnection::query()->orderBy('id')->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function systemUserId(): int
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $authId > 0) {
            return (int) $authId;
        }

        try {
            $id = \App\Models\User::query()->orderBy('id')->value('id');
            if ($id !== null && (int) $id > 0) {
                return (int) $id;
            }
        } catch (\Throwable) {
            // fall through
        }

        return 1;
    }
}
