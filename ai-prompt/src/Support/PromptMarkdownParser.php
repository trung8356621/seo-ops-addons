<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

final class PromptMarkdownParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parse(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return [];
        }

        $segments = preg_split('/^#\s+([^\n]+)/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($segments) || count($segments) < 2) {
            return self::singlePartFallback($markdown);
        }

        array_shift($segments);

        $parts = [];
        $position = 0;

        for ($i = 0; $i < count($segments); $i += 2) {
            $header = trim((string) ($segments[$i] ?? ''));
            $content = trim((string) ($segments[$i + 1] ?? ''));

            if ($header === '' || $content === '') {
                continue;
            }

            [$normalizedHeader, $name] = self::extractHeaderAndName($header);
            $role = self::mapHeaderToRole($normalizedHeader);

            [$content, $meta] = self::extractMeta($role, $content);

            if ($content === '') {
                continue;
            }

            $parts[] = [
                'role' => $role,
                'name' => $name,
                'content' => $content,
                'meta' => $meta,
                'position' => $position++,
            ];
        }

        if ($parts !== []) {
            return $parts;
        }

        return self::singlePartFallback($markdown);
    }

    /**
     * Prompt không dùng tiêu đề # — gộp toàn bộ markdown thành một khối nhiệm vụ.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function singlePartFallback(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return [];
        }

        return [
            [
                'role' => 'task',
                'name' => null,
                'content' => $markdown,
                'meta' => [],
                'position' => 0,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function extractHeaderAndName(string $header): array
    {
        if (! str_contains($header, ':')) {
            return [trim($header), null];
        }

        [$head, $name] = array_map(
            static fn (string $value): string => trim($value),
            explode(':', $header, 2),
        );

        return [$head, $name !== '' ? $name : null];
    }

    private static function mapHeaderToRole(string $header): string
    {
        $header = mb_strtolower(trim($header));

        return match ($header) {
            'vai trò', 'role' => 'role',
            'bối cảnh', 'context' => 'context',
            'ràng buộc', 'constraints' => 'constraints',
            'định dạng đầu ra', 'định dạng', 'formatting' => 'formatting',
            'ràng buộc tổng', 'ràng buộc tổng (global)', 'global_constraints', 'global constraints' => 'global_constraints',
            'nhiệm vụ', 'task' => 'task',
            'nhiệm vụ phụ thuộc', 'nhiệm vụ con', 'sub_task', 'sub-task' => 'sub_task',
            default => 'context',
        };
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function extractMeta(string $role, string $content): array
    {
        $meta = [];

        $rulesMarkers = ['Quy tắc:', 'Quy tắc', 'Rules:', 'Rules'];
        foreach ($rulesMarkers as $marker) {
            [$content, $rules] = self::splitByMarker($content, $marker);
            if ($rules !== null && $rules !== '') {
                $meta['rules'] = $rules;
                break;
            }
        }

        if ($role === 'sub_task') {
            $specificMarkers = [
                'Ràng buộc riêng (sub-prompt):',
                'Ràng buộc riêng (sub-prompt)',
                'Specific constraints:',
                'Specific constraints',
            ];

            foreach ($specificMarkers as $marker) {
                [$content, $specific] = self::splitByMarker($content, $marker);
                if ($specific !== null && $specific !== '') {
                    $meta['specific_constraints'] = $specific;
                    break;
                }
            }
        }

        return [trim($content), $meta];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function splitByMarker(string $content, string $marker): array
    {
        $pattern = '/\n\s*' . preg_quote($marker, '/') . '\s*/iu';
        if (! preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [$content, null];
        }

        $offset = (int) $matches[0][1];
        $length = strlen((string) $matches[0][0]);

        $main = trim(substr($content, 0, $offset));
        $tail = trim(substr($content, $offset + $length));

        return [$main, $tail !== '' ? $tail : null];
    }
}
