<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Idempotent default Prompt + Settings binding for article.faq.generate.
 * Canonical default body is loaded from Hook JSON template/presentation.
 * Does not overwrite operator-edited markdown or existing bindings unless restore is requested.
 */
final class DefaultFaqPromptInstaller
{
    public const HOOK_KEY = 'article.faq.generate';

    public const HOOK_VERSION = '0.1.0';

    public const PROMPT_NAME = 'Tạo FAQ';

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{prompt_id: int, created: bool, binding_set: bool, restored: bool}
     */
    public function install(bool $restoreCanonical = false): array
    {
        $existing = SeoPrompt::query()
            ->where('hook_key', self::HOOK_KEY)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            $existing = SeoPrompt::query()
                ->where('hook_key', self::HOOK_KEY)
                ->orderBy('id')
                ->first();
        }

        $created = false;
        $restored = false;

        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => self::canonicalDefaultMarkdown(),
                'description' => self::canonicalDescription(),
                'hook_key' => self::HOOK_KEY,
                'hook_version' => self::HOOK_VERSION,
                'variables' => self::canonicalVariables(),
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
        } elseif ($restoreCanonical) {
            $existing->markdown_content = self::canonicalDefaultMarkdown();
            $existing->description = self::canonicalDescription();
            $existing->variables = self::canonicalVariables();
            $existing->hook_version = self::HOOK_VERSION;
            $existing->save();
            $restored = true;
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
            'restored' => $restored,
        ];
    }

    /**
     * Default Prompt Markdown from Hook JSON template or fallback.
     */
    public static function canonicalDefaultMarkdown(): string
    {
        $spec = self::loadCanonicalSpec();
        $template = is_array($spec['template'] ?? null) ? $spec['template'] : [];
        $system = trim((string) ($template['system'] ?? ''));
        $user = trim((string) ($template['user'] ?? ''));
        if ($system !== '' && $user !== '') {
            return $system . "\n\n" . $user;
        }

        return <<<'MD'
Return FAQ as a JSON object with key faqs (array of {question, answer}). Generate 4-6 distinct questions. Keep each answer concise: at most 2 short sentences. Language: {{language}}. No markdown fences and no text outside JSON.

Title: {{title}}
Content:
{{content_excerpt}}
MD;
    }

    public static function canonicalDescription(): string
    {
        $spec = self::loadCanonicalSpec();
        $presentation = is_array($spec['presentation'] ?? null) ? $spec['presentation'] : [];
        $fromPresentation = trim((string) ($presentation['description'] ?? ''));
        if ($fromPresentation !== '') {
            return $fromPresentation;
        }

        return trim((string) ($spec['description'] ?? 'Generate frequently asked questions from the article content.'));
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public static function canonicalVariables(): array
    {
        return [
            ['name' => 'title', 'description' => 'Tiêu đề bài viết'],
            ['name' => 'content_excerpt', 'description' => 'Nội dung trích đoạn'],
            ['name' => 'language', 'description' => 'Ngôn ngữ'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadCanonicalSpec(): array
    {
        $path = PromptHookDefinitionLoader::defaultV01Directory()
            .DIRECTORY_SEPARATOR
            .self::HOOK_KEY.'@'.self::HOOK_VERSION.'.json';
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
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
