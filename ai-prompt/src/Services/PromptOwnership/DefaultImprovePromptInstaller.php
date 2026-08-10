<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Services\ArticleImproveExecutionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use App\Models\ApiConnection;

/**
 * Idempotent default Prompt + Settings binding cho article.content.improve.
 * Không ghi đè binding operator đã chọn.
 */
final class DefaultImprovePromptInstaller
{
    public const HOOK_KEY = ArticleImproveExecutionService::HOOK_KEY;

    public const PROMPT_NAME = 'Improve article content (default)';

    public const MARKDOWN = <<<'MD'
Cải thiện nội dung tiếng Việt được cung cấp theo đúng yêu cầu chỉnh sửa.

Yêu cầu chỉnh sửa:
{{instruction}}

Nội dung hiện tại:
{{input}}

Nguyên tắc:
- Chỉ sửa những phần cần thiết để đáp ứng yêu cầu.
- Giữ lại thông tin đúng và hữu ích.
- Không tự viết lại toàn bộ bài nếu yêu cầu chỉ giới hạn ở một đoạn hoặc một vấn đề.
- Không thêm dữ kiện, số liệu hoặc tuyên bố chưa được cung cấp.
- Trả về nội dung đã chỉnh sửa, không giải thích quá trình.

Tone (nếu có): {{tone}}
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
                    ['name' => 'input', 'description' => 'Current article body'],
                    ['name' => 'instruction', 'description' => 'Improve instruction'],
                    ['name' => 'tone', 'description' => 'Site tone'],
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

    /**
     * prompts.user_id NOT NULL — CLI không có auth → lấy user đầu / 1.
     */
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
