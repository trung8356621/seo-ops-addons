<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Deterministic quick CTA sentence templates (editor sidebar, no AI).
 */
final class CtaQuickTemplates
{
    /**
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            'hotline' => [
                'Gọi ngay: [phone]',
                'Liên hệ ngay qua số [phone]',
                'Cần tư vấn? Gọi [phone]',
            ],
            'phone' => [
                'Gọi ngay: [phone]',
                'Liên hệ ngay qua số [phone]',
            ],
            'zalo' => [
                'Nhắn Zalo: [zalo]',
                'Liên hệ Zalo ngay: [zalo]',
            ],
            'email' => [
                'Liên hệ qua email: [email]',
                'Gửi email đến [email]',
            ],
            'address' => [
                'Ghé địa chỉ: [address]',
            ],
            'facebook' => [
                'Xem thêm tại Facebook: [facebook]',
            ],
            'working_hours' => [
                'Thời gian làm việc: [working_hours]',
            ],
            'website' => [
                'Truy cập website: [website]',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedPlaceholders(string $type): array
    {
        $type = mb_strtolower(trim($type));

        return match ($type) {
            'hotline', 'phone' => ['phone', 'label'],
            'zalo' => ['zalo', 'label'],
            'email' => ['email', 'label'],
            'address' => ['address', 'label'],
            'facebook' => ['facebook', 'label'],
            'working_hours' => ['working_hours', 'label'],
            'website' => ['website', 'label'],
            default => array_values(array_unique(array_filter(['label', $type]))),
        };
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, array{default_index: int, templates: list<string>}>
     */
    public static function normalize(array $stored): array
    {
        $defaults = self::defaults();
        $normalized = [];

        foreach ($defaults as $type => $defaultTemplates) {
            $row = is_array($stored[$type] ?? null) ? $stored[$type] : [];
            $templates = [];
            foreach (is_array($row['templates'] ?? null) ? $row['templates'] : $defaultTemplates as $template) {
                $text = trim((string) $template);
                if ($text === '') {
                    continue;
                }
                $validation = self::validate($text, $type);
                if (! $validation['ok']) {
                    continue;
                }
                $templates[] = $text;
            }

            if ($templates === []) {
                $templates = $defaultTemplates;
            }

            $defaultIndex = (int) ($row['default_index'] ?? $row['defaultIndex'] ?? 0);
            $defaultIndex = max(0, min($defaultIndex, count($templates) - 1));

            $normalized[$type] = [
                'default_index' => $defaultIndex,
                'templates' => array_values($templates),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public static function validate(string $template, string $type): array
    {
        $text = trim($template);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Template trống.'];
        }

        $allowed = array_fill_keys(self::allowedPlaceholders($type), true);
        if (preg_match_all('/\[([^\]]+)\]/u', $text, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $key = mb_strtolower(trim((string) $name));
                if ($key === '' || ! isset($allowed[$key])) {
                    return ['ok' => false, 'error' => 'Placeholder không hợp lệ: ['.$name.']'];
                }
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param  array{type?: string, value?: string, label?: string}  $item
     */
    public static function resolve(string $template, array $item): string
    {
        $type = mb_strtolower(trim((string) ($item['type'] ?? '')));
        $value = trim((string) ($item['value'] ?? ($item['label'] ?? '')));
        $label = trim((string) ($item['label'] ?? ($item['value'] ?? '')));

        $map = [
            'phone' => $value,
            'zalo' => $value,
            'email' => $value,
            'address' => $value,
            'facebook' => $value,
            'working_hours' => $value,
            'website' => $value,
            'label' => $label,
            $type => $value,
        ];

        return (string) preg_replace_callback(
            '/\[([^\]]+)\]/u',
            static function (array $match) use ($map): string {
                $key = mb_strtolower(trim((string) ($match[1] ?? '')));
                if ($key !== '' && isset($map[$key]) && trim((string) $map[$key]) !== '') {
                    return (string) $map[$key];
                }

                return (string) ($match[0] ?? '');
            },
            $template,
        );
    }
}
