<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use App\Models\ApiConnection;

/**
 * Idempotent default Prompt + Settings binding for article.featured_image.generate.
 * Không ghi đè binding/markdown operator đã chọn.
 */
final class DefaultNewsThumbnailPromptInstaller
{
    public const HOOK_KEY = 'article.featured_image.generate';

    public const PROMPT_NAME = 'Create news thumbnail';

    public const MARKDOWN = <<<'MD'
Tôi cần thumbnail cho bài viết {{title}}
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
                    ['name' => 'title', 'description' => 'Article title'],
                ],
                'ai_connection_id' => $this->defaultAiConnectionId(),
                'tools' => ImageToolType::Image->value,
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
