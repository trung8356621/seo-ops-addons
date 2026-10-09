<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Welcome;

use App\Models\User;

/**
 * System welcome questions live here. User additions are stored on user_meta.
 */
final class AgentWelcomeQuestions
{
    public const META_KEY = 'agent.welcome_questions';

    /** @return list<array{id: string, label: string, tone: string, questions: list<array{id: string, text: string, system: bool}>}> */
    public static function modules(): array
    {
        return [
            self::module('seo_audit', 'SEO Audit', 'red', [
                ['worst', 'Những bài nào có điểm SEO thấp và nên sửa trước?'],
                ['priority', 'Ưu tiên tối ưu các bài SEO kém nhất trên site này.'],
                ['draft', 'Đưa các bài SEO điểm thấp vào Draft để cải thiện.'],
            ]),
            self::module('keywords', 'Từ khóa', 'blue', [
                ['landscape', 'Website này đang theo dõi những từ khóa SEO nào?'],
                ['coverage', 'Từ khóa nào chưa được bài viết trên site phủ?'],
                ['topics', 'Phân tích các bài viết gắn với nhóm chủ đề này.'],
            ]),
            self::module('content_projects', 'Dự án', 'amber', [
                ['status', 'Các content project trên site đang ở trạng thái nào?'],
                ['drafts', 'Draft nào đang chờ xử lý trong dự án nội dung?'],
                ['plan', 'Kế hoạch nội dung hiện tại của site ra sao?'],
            ]),
            self::module('gsc', 'Thống kê', 'green', [
                ['compare', 'So sánh hiệu suất Search Console tháng này với tháng trước.'],
                ['cross', 'Đối chiếu dữ liệu GSC với các bài có điểm SEO thấp.'],
            ]),
            self::module('articles', 'Bài viết', 'cyan', [
                ['inventory', 'Kho bài viết trên site này hiện có những bài nào?'],
                ['links', 'Site đang có những liên kết nội bộ nào?'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return list<array{id: string, label: string, tone: string, questions: list<array{id: string, text: string, system: bool}>}>
     */
    public static function merge(array $saved): array
    {
        $user = self::sanitize($saved);
        $modules = [];
        foreach (self::modules() as $module) {
            $extra = [];
            foreach ($user[$module['id']] ?? [] as $question) {
                $extra[] = $question;
            }
            $module['questions'] = [...$module['questions'], ...$extra];
            $modules[] = $module;
        }

        return $modules;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<array{id: string, text: string, system: bool}>>
     */
    public static function sanitize(array $input): array
    {
        $allowed = [];
        foreach (self::modules() as $module) {
            $allowed[$module['id']] = true;
        }
        $clean = [];
        foreach ($input as $moduleId => $questions) {
            if (! is_string($moduleId) || ! isset($allowed[$moduleId]) || ! is_array($questions)) {
                continue;
            }
            $rows = [];
            foreach (array_slice(array_values($questions), 0, 30) as $question) {
                if (! is_array($question) || ($question['system'] ?? false) === true) {
                    continue;
                }
                $text = trim((string) ($question['text'] ?? ''));
                $id = trim((string) ($question['id'] ?? ''));
                if ($text === '' || strlen($text) > 500 || $id === '' || strlen($id) > 80) {
                    continue;
                }
                $rows[] = ['id' => $id, 'text' => $text, 'system' => false];
            }
            $clean[$moduleId] = $rows;
        }

        return $clean;
    }

    /** @return list<array{id: string, label: string, tone: string, questions: list<array{id: string, text: string, system: bool}>}> */
    public function forUser(User $user): array
    {
        $raw = $user->getMeta(self::META_KEY, '[]');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return self::merge(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: string, label: string, tone: string, questions: list<array{id: string, text: string, system: bool}>}>
     */
    public function save(User $user, array $input): array
    {
        $clean = self::sanitize($input);
        $user->setMeta(self::META_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::merge($clean);
    }

    /**
     * @param  list<array{id: string, text: string}>  $questions
     * @return array{id: string, label: string, tone: string, questions: list<array{id: string, text: string, system: bool}>}
     */
    private static function module(string $id, string $label, string $tone, array $questions): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'tone' => $tone,
            'questions' => array_map(
                static fn (array $question): array => [
                    'id' => $id.'.'.$question[0],
                    'text' => $question[1],
                    'system' => true,
                ],
                $questions,
            ),
        ];
    }
}
