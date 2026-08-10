<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentIntentResolution;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectNaturalLanguageAdapter;

/**
 * Resolution order:
 * 1. Exact slash command
 * 2. Slash alias
 * 3. Selected template
 * 4. Deterministic intent/rule match
 * 5. AI intent routing (structured only)
 * 6. General assistant response
 *
 * Never auto-executes write capabilities.
 */
final class AgentIntentRouter
{
    private const LOW_CONFIDENCE = 0.55;

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentChatTemplateRegistry $templates,
        private readonly ContentProjectNaturalLanguageAdapter $legacyNl,
        private readonly AgentExecutionPlanService $plans,
    ) {}

    /**
     * @param  array{
     *     template_key?: string|null,
     *     selected_skill_key?: string|null,
     *     hints?: array<string, mixed>,
     *     ai_intent?: array{skill_key?: string, confidence?: float, extracted_inputs?: array<string, mixed>, missing_fields?: list<string>}|null
     * }  $options
     */
    public function resolve(string $text, array $options = []): AgentIntentResolution
    {
        $trimmed = trim($text);

        if (isset($options['selected_skill_key']) && is_string($options['selected_skill_key']) && $options['selected_skill_key'] !== '') {
            $skill = $this->skills->get($options['selected_skill_key']);
            if ($skill !== null) {
                return new AgentIntentResolution(
                    skillKey: $skill->key,
                    confidence: 1.0,
                    source: AgentIntentResolution::SOURCE_TEMPLATE,
                    extractedInputs: is_array($options['hints'] ?? null) ? $options['hints'] : [],
                    message: 'Skill selected.',
                );
            }
        }

        $templateKey = $options['template_key'] ?? null;
        if (is_string($templateKey) && $templateKey !== '') {
            $template = $this->templates->get($templateKey);
            if ($template !== null && $template->skillKey !== null) {
                return new AgentIntentResolution(
                    skillKey: $template->skillKey,
                    confidence: 1.0,
                    source: AgentIntentResolution::SOURCE_TEMPLATE,
                    extractedInputs: is_array($options['hints'] ?? null) ? $options['hints'] : [],
                    message: 'Template mapped to skill.',
                );
            }
        }

        if (str_starts_with($trimmed, '/')) {
            $token = preg_split('/\s+/', $trimmed, 2)[0] ?? $trimmed;
            $skill = $this->skills->resolveSlashCommand($token);
            if ($skill !== null) {
                $canonical = mb_strtolower($skill->slashCommand);
                $source = mb_strtolower($token) === $canonical
                    ? AgentIntentResolution::SOURCE_SLASH
                    : AgentIntentResolution::SOURCE_ALIAS;

                return new AgentIntentResolution(
                    skillKey: $skill->key,
                    confidence: 1.0,
                    source: $source,
                    extractedInputs: $this->extractTrailingArgs($trimmed),
                    message: 'Slash command resolved.',
                );
            }
        }

        $multi = $this->plans->detectMultiIntent($trimmed);
        if ($multi !== null) {
            return $multi;
        }

        $deterministic = $this->deterministicMatch($trimmed, is_array($options['hints'] ?? null) ? $options['hints'] : []);
        if ($deterministic !== null && $deterministic->confidence >= self::LOW_CONFIDENCE) {
            return $deterministic;
        }

        $aiIntent = $options['ai_intent'] ?? null;
        if (is_array($aiIntent) && isset($aiIntent['skill_key']) && is_string($aiIntent['skill_key'])) {
            $confidence = (float) ($aiIntent['confidence'] ?? 0.0);
            $skill = $this->skills->get($aiIntent['skill_key']);
            if ($skill !== null && $confidence >= self::LOW_CONFIDENCE) {
                return new AgentIntentResolution(
                    skillKey: $skill->key,
                    confidence: $confidence,
                    source: AgentIntentResolution::SOURCE_AI,
                    extractedInputs: is_array($aiIntent['extracted_inputs'] ?? null) ? $aiIntent['extracted_inputs'] : [],
                    missingFields: is_array($aiIntent['missing_fields'] ?? null)
                        ? array_values(array_map('strval', $aiIntent['missing_fields']))
                        : [],
                    message: 'AI structured intent.',
                );
            }

            if ($skill !== null && $confidence < self::LOW_CONFIDENCE) {
                return $this->lowConfidenceChoices($trimmed);
            }
        }

        if ($deterministic !== null) {
            return $this->lowConfidenceChoices($trimmed, $deterministic->candidateSkillKeys);
        }

        return new AgentIntentResolution(
            skillKey: null,
            confidence: 0.0,
            source: AgentIntentResolution::SOURCE_ASSISTANT,
            message: 'General assistant response — no capability mapped.',
        );
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function deterministicMatch(string $text, array $hints): ?AgentIntentResolution
    {
        $parsed = $this->legacyNl->parseIntent($text, $hints);
        $capability = $parsed['capability'] ?? null;
        if (! is_string($capability) || $capability === '') {
            return $this->extraDeterministicRules($text, $hints);
        }

        $skill = $this->findSkillByCapability($capability);
        if ($skill === null) {
            return $this->extraDeterministicRules($text, $hints);
        }

        return new AgentIntentResolution(
            skillKey: $skill->key,
            confidence: (float) ($parsed['confidence'] ?? 0.0),
            source: AgentIntentResolution::SOURCE_DETERMINISTIC,
            extractedInputs: is_array($parsed['input'] ?? null) ? $parsed['input'] : [],
            missingFields: is_array($parsed['missing_fields'] ?? null)
                ? array_values(array_map('strval', $parsed['missing_fields']))
                : [],
            message: 'Deterministic intent match.',
        );
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function extraDeterministicRules(string $text, array $hints): ?AgentIntentResolution
    {
        $normalized = mb_strtolower($text);

        $rules = [
            ['keywords' => ['phân tích từ khóa', 'analyze keyword', 'gom nhóm từ khóa', 'tìm keyword cơ hội'], 'skill' => 'keyword.analyze', 'confidence' => 0.85],
            ['keywords' => ['topical map', 'xây topical', 'build topical'], 'skill' => 'keyword.build_topical_map', 'confidence' => 0.85],
            ['keywords' => ['thu thập serp', 'collect serp', 'import serp', 'phân tích top 10'], 'skill' => 'serp.collect', 'confidence' => 0.8],
            ['keywords' => ['content gap', 'xem content gap', 'list content gap'], 'skill' => 'serp.list_content_gaps', 'confidence' => 0.82],
            ['keywords' => ['báo cáo hôm nay', 'daily report'], 'skill' => 'operations.daily_report', 'confidence' => 0.9],
            ['keywords' => ['sức khỏe site', 'site health', 'kiểm tra site health', 'audit website'], 'skill' => 'operations.site_health', 'confidence' => 0.9],
            ['keywords' => ['chạy lại ảnh', 'rerun image', 'tạo lại ảnh'], 'skill' => 'content_project.rerun', 'confidence' => 0.88, 'inputs' => ['rerun_step' => 'image']],
            ['keywords' => ['chạy lại từ outline', 'rerun outline'], 'skill' => 'content_project.rerun', 'confidence' => 0.88, 'inputs' => ['rerun_step' => 'outline']],
            ['keywords' => ['chạy lại phần bài viết', 'rerun article', 'viết lại bài'], 'skill' => 'content_project.rerun', 'confidence' => 0.86, 'inputs' => ['rerun_step' => 'article']],
            ['keywords' => ['thêm keyword vào project', 'thêm các keyword', 'add keywords to project'], 'skill' => 'content_project.add_items', 'confidence' => 0.88],
            ['keywords' => ['bắt đầu duyệt', 'start review'], 'skill' => 'content_project.start_review', 'confidence' => 0.9],
            ['keywords' => ['duyệt các bài', 'approve items', 'duyệt bài đã chọn'], 'skill' => 'content_project.approve', 'confidence' => 0.88],
            ['keywords' => ['lên lịch mỗi ngày', 'lên lịch 2 bài', 'schedule daily'], 'skill' => 'content_project.auto_schedule', 'confidence' => 0.86],
            ['keywords' => ['dời lịch', 'move schedule'], 'skill' => 'content_project.move_schedule', 'confidence' => 0.86],
            ['keywords' => ['xuất bản ngay', 'publish now'], 'skill' => 'content_project.publish_now', 'confidence' => 0.88],
            ['keywords' => ['thử lại bài publish', 'retry publish', 'publish lỗi'], 'skill' => 'content_project.retry_publish', 'confidence' => 0.88],
            ['keywords' => ['dừng quá trình', 'stop execution'], 'skill' => 'content_project.stop_execution', 'confidence' => 0.9],
            ['keywords' => ['tiếp tục quá trình', 'resume execution'], 'skill' => 'content_project.resume_execution', 'confidence' => 0.9],
            ['keywords' => ['archive project', 'lưu trữ project'], 'skill' => 'content_project.archive', 'confidence' => 0.88],
            ['keywords' => ['restore project', 'khôi phục project'], 'skill' => 'content_project.restore', 'confidence' => 0.88],
            ['keywords' => ['tạo content project tháng', 'tạo project tháng sau', 'create monthly content project'], 'skill' => 'content_project.create', 'confidence' => 0.9],
            ['keywords' => ['chạy tạo bài', 'generate selected', 'tạo bài cho tất cả'], 'skill' => 'content_project.generate', 'confidence' => 0.86],
            ['keywords' => ['kiểm tra project bị kẹt', 'project stuck'], 'skill' => 'content_project.status', 'confidence' => 0.88],
            ['keywords' => ['ctr thấp', 'impression cao', 'gsc opportunity'], 'skill' => 'operations.site_health', 'confidence' => 0.55],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (! str_contains($normalized, $keyword)) {
                    continue;
                }

                $inputs = $rule['inputs'] ?? [];
                if (! is_array($inputs)) {
                    $inputs = [];
                }

                return new AgentIntentResolution(
                    skillKey: (string) $rule['skill'],
                    confidence: (float) $rule['confidence'],
                    source: AgentIntentResolution::SOURCE_DETERMINISTIC,
                    extractedInputs: array_merge($hints, $inputs),
                    message: 'Rule-based intent match.',
                );
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $extra
     */
    private function lowConfidenceChoices(string $text, array $extra = []): AgentIntentResolution
    {
        $candidates = array_values(array_unique(array_filter([
            ...$extra,
            'content_project.create',
            'content_project.add_items',
            'content_project.status',
        ])));

        return new AgentIntentResolution(
            skillKey: null,
            confidence: 0.3,
            source: AgentIntentResolution::SOURCE_LOW_CONFIDENCE,
            candidateSkillKeys: $candidates,
            requiresUserChoice: true,
            message: 'Bạn muốn làm việc nào?',
        );
    }

    private function findSkillByCapability(string $capability): ?\Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition
    {
        foreach ($this->skills->all(includeHidden: true) as $skill) {
            if ($skill->capability === $capability) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrailingArgs(string $slashText): array
    {
        $parts = preg_split('/\s+/', trim($slashText), 2);
        if (! is_array($parts) || count($parts) < 2) {
            return [];
        }

        return ['raw_args' => $parts[1]];
    }
}
