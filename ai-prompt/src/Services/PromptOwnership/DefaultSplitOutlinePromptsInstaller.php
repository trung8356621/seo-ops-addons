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

    /** Legacy combined-output wording — safe signature for system-default upgrade. */
    public const LEGACY_OUTLINE_SIGNATURE = '2 loại đầu ra riêng biệt';

    public const OUTLINE_MARKDOWN = <<<'MD'
## Vai trò
Chuyên gia tối ưu hóa công cụ tìm kiếm (SEO Specialist) và Chuyên gia nội dung.

## Đầu vào
{{input}}

## Nhiệm vụ: Dàn ý
Tạo một dàn ý chi tiết dựa trên đầu vào phía trên.
Đảm bảo hệ thống tiêu đề rõ ràng (H1, H2, H3), bao gồm phần Giới thiệu, Nội dung chính, Kết luận và Câu hỏi thường gặp (FAQ).

Quy tắc:
Sử dụng Markdown. Trong nội dung dàn ý phải tích hợp ít nhất một bảng so sánh (10 - 20 hàng, 2 - 5 cột) hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet.

## Định dạng đầu ra
- Markdown
- Toàn bộ đầu ra sử dụng Tiếng Việt.
- Trả về trực tiếp nội dung dàn ý (H1/H2/H3). Không bọc START/END marker.
MD;

    public const VOCABULARY_MARKDOWN = <<<'MD'
## Vai trò
Chuyên gia tối ưu hóa công cụ tìm kiếm (SEO Specialist) và Chuyên gia Data/Ngôn ngữ học máy tính.

## Đầu vào
{{input}}

## Nhiệm vụ: Từ vựng
Nghiên cứu từ vựng/ngữ nghĩa chuyên sâu dựa trên đầu vào phía trên.
Tạo các danh sách (mỗi loại đúng 5 mục): Holonymy, Synonyms, Antonyms, Long-tail keywords, Semantic keywords, Salient keywords, Salient LSI keywords, Semantic LSI entities, Relational entities, Relevant entities, Semantic entities, Close entities, Salient entities, Related topics, và N-grams (Unigrams, Bigrams, Trigrams, Quadgrams, Quinquigrams).

Quy tắc:
Bắt buộc trả về định dạng Markdown thuần túy (không bọc trong block code JSON). Sử dụng thẻ Heading 3 (###) cho tên của mỗi nhóm từ khóa/thực thể (ví dụ: ### Holonymy, ### Synonyms). Bên dưới mỗi Heading, liệt kê chính xác 5 mục bằng dấu gạch đầu dòng (-). Không viết thêm bất kỳ văn bản giải thích nào khác.

## Định dạng đầu ra
- Markdown
- Toàn bộ đầu ra sử dụng ngôn ngữ {{language}} (mặc định Tiếng Việt nếu trống).
- Trả về trực tiếp nội dung từ vựng. Không bọc START/END marker.
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
                ['name' => 'input', 'description' => 'Canonical task subject (keyword / planning context)'],
                ['name' => 'language', 'description' => 'Output language'],
            ],
            refreshMarkdown: true,
            legacySignature: self::LEGACY_OUTLINE_SIGNATURE,
        );

        $vocabulary = $this->installPrompt(
            hookKey: self::VOCABULARY_HOOK,
            promptName: self::VOCABULARY_PROMPT_NAME,
            markdown: self::VOCABULARY_MARKDOWN,
            portableUuid: self::VOCABULARY_PORTABLE_UUID,
            variables: [
                ['name' => 'input', 'description' => 'Canonical task subject (keyword / planning context)'],
                ['name' => 'outline', 'description' => 'Outline markdown from structure step (optional context)'],
                ['name' => 'language', 'description' => 'Output language'],
            ],
            refreshMarkdown: true,
            legacySignature: 'Nghiên cứu từ vựng / ngữ nghĩa cho **một bài viết cụ thể**',
        );

        return [
            'outline' => $outline,
            'vocabulary' => $vocabulary,
        ];
    }

    /**
     * Force-refresh system Outline + Vocabulary prompts to {{input}} self-contained contract.
     * Only upgrades known system-default / legacy-signature bodies — never custom prompts.
     *
     * @return array{outline: array{prompt_id: int, updated: bool}, vocabulary: array{prompt_id: int, updated: bool}}
     */
    public function refreshSplitPromptInputContract(): array
    {
        return $this->refreshSplitPromptMarkerlessContract();
    }

    /**
     * Upgrade system-default #22/#23: remove AI marker protocol; keep {{input}}.
     * Skips operator-customized prompts.
     *
     * @return array{outline: array{prompt_id: int, updated: bool}, vocabulary: array{prompt_id: int, updated: bool}}
     */
    public function refreshSplitPromptMarkerlessContract(): array
    {
        $outline = $this->installPrompt(
            hookKey: self::OUTLINE_HOOK,
            promptName: self::OUTLINE_PROMPT_NAME,
            markdown: self::OUTLINE_MARKDOWN,
            portableUuid: self::OUTLINE_PORTABLE_UUID,
            variables: [
                ['name' => 'input', 'description' => 'Canonical task subject (keyword / planning context)'],
                ['name' => 'language', 'description' => 'Output language'],
            ],
            refreshMarkdown: true,
            legacySignature: self::LEGACY_OUTLINE_SIGNATURE,
        );

        $vocabulary = $this->refreshVocabularyPromptContract();

        return [
            'outline' => [
                'prompt_id' => $outline['prompt_id'],
                'updated' => (bool) ($outline['markdown_refreshed'] ?? false) || $outline['created'],
            ],
            'vocabulary' => $vocabulary,
        ];
    }

    /**
     * Force-refresh system Vocabulary prompt contract ({{input}} self-contained).
     *
     * @return array{prompt_id: int, updated: bool}
     */
    public function refreshVocabularyPromptContract(): array
    {
        $result = $this->installPrompt(
            hookKey: self::VOCABULARY_HOOK,
            promptName: self::VOCABULARY_PROMPT_NAME,
            markdown: self::VOCABULARY_MARKDOWN,
            portableUuid: self::VOCABULARY_PORTABLE_UUID,
            variables: [
                ['name' => 'input', 'description' => 'Canonical task subject (keyword / planning context)'],
                ['name' => 'outline', 'description' => 'Outline markdown from structure step (optional context)'],
                ['name' => 'language', 'description' => 'Output language'],
            ],
            refreshMarkdown: true,
            legacySignature: 'Nghiên cứu từ vựng / ngữ nghĩa cho **một bài viết cụ thể**',
        );

        return [
            'prompt_id' => $result['prompt_id'],
            'updated' => (bool) ($result['markdown_refreshed'] ?? false) || $result['created'],
        ];
    }

    /**
     * @param  list<array{name: string, description: string}>  $variables
     * @return array{prompt_id: int, created: bool, binding_set: bool, markdown_refreshed?: bool}
     */
    private function installPrompt(
        string $hookKey,
        string $promptName,
        string $markdown,
        string $portableUuid,
        array $variables,
        bool $refreshMarkdown = false,
        ?string $legacySignature = null,
    ): array {
        $existing = $this->findExisting($hookKey, $promptName, $portableUuid);

        $created = false;
        $markdownRefreshed = false;
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
        } elseif ($refreshMarkdown && $this->mayRefreshSystemDefault($existing, $portableUuid, $legacySignature, $markdown)) {
            $existing->fill([
                'markdown_content' => $markdown,
                'variables' => $variables,
            ]);
            $settings = is_array($existing->settings) ? $existing->settings : [];
            $settings['is_system_default'] = true;
            $settings['ownership'] = $settings['ownership'] ?? 'settings_binding';
            $settings['portable_uuid'] = $portableUuid;
            $existing->settings = $settings;
            $existing->save();
            $markdownRefreshed = true;
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
            'markdown_refreshed' => $markdownRefreshed,
        ];
    }

    private function mayRefreshSystemDefault(
        SeoPrompt $existing,
        string $portableUuid,
        ?string $legacySignature,
        string $canonicalMarkdown,
    ): bool {
        $settings = is_array($existing->settings) ? $existing->settings : [];
        $isSystemDefault = ($settings['is_system_default'] ?? false) === true
            || (string) ($settings['portable_uuid'] ?? '') === $portableUuid;
        if (! $isSystemDefault) {
            return false;
        }

        $current = trim((string) ($existing->markdown_content ?? ''));
        $canonical = trim($canonicalMarkdown);
        if ($current === $canonical) {
            return false;
        }

        // System defaults still instructing AI to emit START/END markers → upgrade.
        if ($this->containsLegacyMarkerProtocol($current)) {
            return true;
        }

        // Already on {{input}} contract and not matching known legacy — treat as customized in-place.
        if (str_contains($current, '{{input}}')
            && ($legacySignature === null || ! str_contains($current, $legacySignature))
            && ! str_contains($current, '2 loại đầu ra riêng biệt')
        ) {
            return false;
        }

        // Upgrade when missing {{input}}, or still carrying known legacy combined wording.
        if (! str_contains($current, '{{input}}')) {
            return true;
        }

        if ($legacySignature !== null && str_contains($current, $legacySignature)) {
            return true;
        }

        return str_contains($current, '2 loại đầu ra riêng biệt');
    }

    private function containsLegacyMarkerProtocol(string $markdown): bool
    {
        return str_contains($markdown, 'START_TASK_1_OUTLINE')
            || str_contains($markdown, 'START_TASK_2_VOCABULARY')
            || str_contains($markdown, 'Toàn bộ kết quả của nhiệm vụ này phải được bọc');
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
