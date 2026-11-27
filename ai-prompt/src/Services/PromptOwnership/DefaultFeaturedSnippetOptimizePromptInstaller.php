<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use App\Models\ApiConnection;

/**
 * Idempotent default Prompt + Settings binding for article.featured_snippet.optimize.
 * Does not overwrite an operator-chosen binding.
 */
final class DefaultFeaturedSnippetOptimizePromptInstaller
{
    public const HOOK_KEY = 'article.featured_snippet.optimize';

    public const PROMPT_NAME = 'Optimize Featured Snippet table (default)';

    public const MARKDOWN = <<<'MD'
Optimize the existing Featured Snippet comparison table for search.

Article title: {{post_title}}
Focus keyword: {{focus_keyword}}
Language: {{language}}
Domain: {{domain}}

Scoring target (highest tier):
- Target data rows (excellent): {{target_data_rows}}
- Thresholds: min={{rows_min}}, good={{rows_range}}, excellent={{rows_max}}
- Columns allowed: {{min_columns}}–{{max_columns}}
- Current data rows: {{current_data_rows}}

Instruction:
{{optimize_instruction}}

Current table HTML:
{{table_html}}

Rules:
- Return ONE HTML table only (<table>…</table>).
- Preserve every existing cell value and meaning.
- Add useful rows only when needed to reach {{target_data_rows}} data rows.
- Do not invent unsupported facts or numbers.
- Do not add article prose, headings, or other sections.
- Do not remove existing rows/columns unless they are empty placeholders.
MD;

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{prompt_id: int, created: bool, binding_set: bool}
     */
    public function install(): array
    {
        $existing = SeoPrompt::query()
            ->where('hook_key', self::HOOK_KEY)
            ->where('name', self::PROMPT_NAME)
            ->orderBy('id')
            ->first();

        $created = false;
        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => self::MARKDOWN,
                'hook_key' => self::HOOK_KEY,
                'hook_version' => '0.1.0',
                'variables' => [
                    ['name' => 'table_html', 'description' => 'Selected table HTML'],
                    ['name' => 'target_data_rows', 'description' => 'Excellent tier data-row target'],
                    ['name' => 'optimize_instruction', 'description' => 'Optimize instruction'],
                    ['name' => 'post_title', 'description' => 'Article title'],
                    ['name' => 'focus_keyword', 'description' => 'Focus keyword'],
                    ['name' => 'language', 'description' => 'Language'],
                    ['name' => 'domain', 'description' => 'Site domain'],
                    ['name' => 'rows_min', 'description' => 'Average tier rows'],
                    ['name' => 'rows_range', 'description' => 'Good tier rows'],
                    ['name' => 'rows_max', 'description' => 'Excellent tier rows'],
                    ['name' => 'min_columns', 'description' => 'Min columns'],
                    ['name' => 'max_columns', 'description' => 'Max columns'],
                    ['name' => 'current_data_rows', 'description' => 'Current data rows'],
                ],
                'ai_connection_id' => $this->defaultAiConnectionId(),
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                ],
            ]);
            $existing->save();
            $created = true;
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[self::HOOK_KEY])) {
            $this->settings->savePromptHookBindings([
                self::HOOK_KEY => $promptId,
            ]);
            $bindingSet = true;
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
        ];
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
