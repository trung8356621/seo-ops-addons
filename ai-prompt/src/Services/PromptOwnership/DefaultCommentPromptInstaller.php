<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use App\Models\ApiConnection;

/**
 * Idempotent default Prompt + Settings binding for article.comment.generate.
 */
final class DefaultCommentPromptInstaller
{
    public const HOOK_KEY = 'article.comment.generate';

    public const PROMPT_NAME = 'Generate article comments (default)';

    public const MARKDOWN = <<<'MD'
# Task

Viết 3 bình luận seeding tự nhiên bằng Tiếng Việt để tăng tương tác và hỗ trợ SEO cho bài viết: "{{post_title}}".

**Yêu cầu:**
- Đóng vai độc giả thực, bình luận mang tính đóng góp hoặc đặt câu hỏi mở rộng.
- Email phải khớp với Tên.
- Không có câu chào, không giải thích. Chỉ xuất đúng 3 dòng theo định dạng phân tách dưới đây:

Họ và tên | Email | Nội dung bình luận
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
            $connectionId = $this->defaultAiConnectionId();
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => self::MARKDOWN,
                'hook_key' => self::HOOK_KEY,
                'hook_version' => '0.1.0',
                'variables' => [
                    ['name' => 'post_title', 'description' => 'Article title'],
                ],
                'ai_connection_id' => $connectionId,
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
