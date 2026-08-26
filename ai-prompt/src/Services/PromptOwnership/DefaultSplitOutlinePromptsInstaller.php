<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPortableIdentity;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Idempotent install: Outline structure + Vocabulary prompts + Settings bindings.
 * Legacy combined prompt (article.outline.generate) is never modified or rebound.
 */
final class DefaultSplitOutlinePromptsInstaller
{
    public const OUTLINE_HOOK = ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK;

    public const VOCABULARY_HOOK = ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK;

    public const OUTLINE_PROMPT_NAME = 'Dàn ý bài viết — Outline';

    public const VOCABULARY_PROMPT_NAME = 'Từ vựng bài viết — Vocabulary';

    public const OUTLINE_PORTABLE_UUID = '6f3a9c2e-1b4d-4a8f-9e2c-outline00001';

    public const VOCABULARY_PORTABLE_UUID = '6f3a9c2e-1b4d-4a8f-9e2c-vocab000001';

    public const OUTLINE_MARKDOWN = <<<'MD'
## Vai trò
Chuyên gia tối ưu hóa công cụ tìm kiếm (SEO Specialist) và Chuyên gia Data/Ngôn ngữ học máy tính.

## Bối cảnh
Cần tạo bộ tài liệu chuẩn bị cho quy trình sản xuất nội dung SEO. Hệ thống yêu cầu 2 loại đầu ra riêng biệt: một bản dàn ý trực quan cho Copywriter và một bộ dữ liệu thô (raw data) dạng JSON để nạp vào tool tự động chấm điểm bài viết (Entity/N-gram checking).

## Nhiệm vụ: Dàn ý
Lập một dàn ý chi tiết cho bài viết về chủ đề '{{post_title}}'. Đảm bảo hệ thống tiêu đề rõ ràng (H1, H2, H3), bao gồm phần Giới thiệu, Nội dung chính, Kết luận và Câu hỏi thường gặp (FAQ).

Quy tắc:
Sử dụng Markdown. Trong nội dung dàn ý phải tích hợp ít nhất một bảng so sánh (10 - 20 hàng, 2 - 5 cột) hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet.
Toàn bộ kết quả của nhiệm vụ này phải được bọc trong cặp thẻ: [START_TASK_1_OUTLINE] và [END_TASK_1_OUTLINE].

## Định dạng đầu ra
- Toàn bộ đầu ra sử dụng Tiếng Việt.
- Bắt buộc chỉ trả về vùng:

[START_TASK_1_OUTLINE]
(Nội dung dàn ý viết ở đây)
[END_TASK_1_OUTLINE]
MD;

    public const VOCABULARY_MARKDOWN = <<<'MD'
## Vai trò
Chuyên gia tối ưu hóa công cụ tìm kiếm (SEO Specialist) và Chuyên gia Data/Ngôn ngữ học máy tính.

## Bối cảnh
Cần tạo bộ tài liệu chuẩn bị cho quy trình sản xuất nội dung SEO. Hệ thống yêu cầu 2 loại đầu ra riêng biệt: một bản dàn ý trực quan cho Copywriter và một bộ dữ liệu thô (raw data) dạng JSON để nạp vào tool tự động chấm điểm bài viết (Entity/N-gram checking).

## Nhiệm vụ: Từ vựng
Thực hiện nghiên cứu từ vựng và ngữ nghĩa chuyên sâu cho chủ đề trên. Tạo các danh sách (mỗi loại đúng 5 mục): Holonymy, Synonyms, Antonyms, Long-tail keywords, Semantic keywords, Salient keywords, Salient LSI keywords, Semantic LSI entities, Relational entities, Relevant entities, Semantic entities, Close entities, Salient entities, Related topics, và N-grams (Unigrams, Bigrams, Trigrams, Quadgrams, Quinquigrams).

Quy tắc:
Bắt buộc trả về định dạng Markdown thuần túy (không bọc trong block code JSON). Sử dụng thẻ Heading 3 (###) cho tên của mỗi nhóm từ khóa/thực thể (ví dụ: ### Holonymy, ### Synonyms). Bên dưới mỗi Heading, liệt kê chính xác 5 mục bằng dấu gạch đầu dòng (-). Không viết thêm bất kỳ văn bản giải thích nào khác.
Toàn bộ kết quả của nhiệm vụ này phải được bọc trong cặp thẻ: [START_TASK_2_VOCABULARY] và [END_TASK_2_VOCABULARY].

## Định dạng đầu ra
- Toàn bộ đầu ra sử dụng Tiếng Việt.
- Bắt buộc chỉ trả về vùng:

[START_TASK_2_VOCABULARY]
(Nội dung từ vựng viết ở đây)
[END_TASK_2_VOCABULARY]
MD;

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptPortableIdentity $portableIdentity = new PromptPortableIdentity,
    ) {}

    /**
     * @return array{
     *   outline: array{prompt_id: int, created: bool, binding_set: bool},
     *   vocabulary: array{prompt_id: int, created: bool, binding_set: bool}
     * }
     */
    public function install(): array
    {
        $outline = $this->installPrompt(
            hookKey: self::OUTLINE_HOOK,
            promptName: self::OUTLINE_PROMPT_NAME,
            markdown: self::OUTLINE_MARKDOWN,
            portableUuid: self::OUTLINE_PORTABLE_UUID,
            variables: [
                ['name' => 'post_title', 'description' => 'Article title / topic'],
                ['name' => 'keyword', 'description' => 'Focus keyword'],
                ['name' => 'topic', 'description' => 'Topic alias'],
            ],
        );

        $vocabulary = $this->installPrompt(
            hookKey: self::VOCABULARY_HOOK,
            promptName: self::VOCABULARY_PROMPT_NAME,
            markdown: self::VOCABULARY_MARKDOWN,
            portableUuid: self::VOCABULARY_PORTABLE_UUID,
            variables: [
                ['name' => 'post_title', 'description' => 'Article title / topic'],
                ['name' => 'keyword', 'description' => 'Focus keyword'],
                ['name' => 'topic', 'description' => 'Topic alias'],
            ],
        );

        return [
            'outline' => $outline,
            'vocabulary' => $vocabulary,
        ];
    }

    /**
     * @param  list<array{name: string, description: string}>  $variables
     * @return array{prompt_id: int, created: bool, binding_set: bool}
     */
    private function installPrompt(
        string $hookKey,
        string $promptName,
        string $markdown,
        string $portableUuid,
        array $variables,
    ): array {
        $existing = $this->findExisting($hookKey, $promptName, $portableUuid);

        $created = false;
        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => $promptName,
                'title' => $promptName,
                'markdown_content' => $markdown,
                'hook_key' => $hookKey,
                'hook_version' => '0.1.0',
                'variables' => $variables,
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                    'portable_uuid' => $portableUuid,
                ],
            ]);
            $existing->save();
            $this->portableIdentity->ensure($existing);
            $created = true;
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[$hookKey])) {
            $this->settings->savePromptHookBindings([
                $hookKey => $promptId,
            ]);
            $bindingSet = true;
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
        ];
    }

    private function findExisting(string $hookKey, string $promptName, string $portableUuid): ?SeoPrompt
    {
        $byPortable = SeoPrompt::query()
            ->where('hook_key', $hookKey)
            ->where('settings->portable_uuid', $portableUuid)
            ->orderBy('id')
            ->first();
        if ($byPortable instanceof SeoPrompt) {
            return $byPortable;
        }

        $byName = SeoPrompt::query()
            ->where('hook_key', $hookKey)
            ->where('name', $promptName)
            ->orderBy('id')
            ->first();

        return $byName instanceof SeoPrompt ? $byName : null;
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
