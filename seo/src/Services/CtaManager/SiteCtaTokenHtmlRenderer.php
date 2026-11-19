<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\CtaManager;

use Omnichannel\Addons\Seo\Support\CtaLinkFormatter;

/**
 * Resolve [[cta:type]] / [type] tokens to HTML anchors or plain text.
 * Keeps templates token-based; rendering is display/publish only.
 */
final class SiteCtaTokenHtmlRenderer
{
    /** @var list<string> */
    private const PLAIN_TEXT_TYPES = ['address', 'working_hours'];

    /**
     * @param  array<string, array{value: string, href: string, plain_text: bool}>  $contactsByType
     * @return array{html: string, unresolved: list<string>, unsupported: list<string>}
     */
    public function render(string $content, array $contactsByType): array
    {
        $unresolved = [];
        $unsupported = [];
        $html = (string) preg_replace_callback(
            '/\[\[cta:([a-z0-9_]+)\]\]|\[([a-z0-9_]+)\]/iu',
            function (array $matches) use ($contactsByType, &$unresolved, &$unsupported): string {
                $type = mb_strtolower(trim((string) ($matches[1] !== '' ? $matches[1] : ($matches[2] ?? ''))));
                if ($type === '') {
                    return $this->esc((string) $matches[0]);
                }
                if (! isset($contactsByType[$type])) {
                    $unsupported[] = $type;
                    $unresolved[] = (string) $matches[0];

                    return $this->esc((string) $matches[0]);
                }

                $row = $contactsByType[$type];
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    $unsupported[] = $type;
                    $unresolved[] = (string) $matches[0];

                    return $this->esc((string) $matches[0]);
                }

                if ($this->isPlainTextType($type) || ! empty($row['plain_text'])) {
                    return $this->esc($value);
                }

                $href = $this->safeHref($type, $value, (string) ($row['href'] ?? ''));
                if ($href === '') {
                    return $this->esc($value);
                }

                $label = $this->anchorLabel($type, $value);

                return '<a href="'.$this->escAttr($href).'">'.$this->esc($label).'</a>';
            },
            $content,
        );

        return [
            'html' => $html !== '' ? $html : $this->esc($content),
            'unresolved' => array_values(array_unique($unresolved)),
            'unsupported' => array_values(array_unique($unsupported)),
        ];
    }

    public function isPlainTextType(string $type): bool
    {
        $base = $this->baseType($type);

        return in_array($base, self::PLAIN_TEXT_TYPES, true)
            || CtaLinkFormatter::isPlainTextType($base);
    }

    public function anchorLabel(string $type, string $value): string
    {
        $base = $this->baseType($type);

        return match ($base) {
            'zalo' => 'Zalo',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
            'linkedin' => 'LinkedIn',
            'pinterest' => 'Pinterest',
            'tiktok' => 'TikTok',
            'threads' => 'Threads',
            'twitter', 'x' => 'X',
            'email' => 'Email',
            'website' => 'Website',
            'phone', 'hotline' => $value,
            default => ucfirst($base),
        };
    }

    public function safeHref(string $type, string $value, string $preferredHref = ''): string
    {
        $base = $this->baseType($type);
        if ($this->isPlainTextType($base)) {
            return '';
        }
        // Address formatter historically builds Maps URLs — never use for CTA token render.
        if ($base === 'address' || $base === 'working_hours') {
            return '';
        }

        $preferredHref = trim($preferredHref);
        if ($preferredHref !== '' && preg_match('/\s|javascript:|data:|vbscript:/i', $preferredHref) === 1) {
            return '';
        }
        if (preg_match('/^(?:javascript|data|vbscript):/i', trim($value)) === 1) {
            return '';
        }

        if ($preferredHref !== '' && $this->isSafeUrl($preferredHref)) {
            return $preferredHref;
        }

        $formatted = CtaLinkFormatter::format($base, $value);
        if ($this->isSafeUrl($formatted)) {
            return $formatted;
        }

        return '';
    }

    public function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/\s|javascript:|data:|vbscript:/i', $url) === 1) {
            return false;
        }

        if (preg_match('/^mailto:(.+)$/i', $url, $m) === 1) {
            $address = explode('?', $m[1], 2)[0];

            return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
        }

        if (preg_match('/^tel:(.+)$/i', $url, $m) === 1) {
            return preg_match('/^\+?\d{3,}$/', $m[1]) === 1;
        }

        return preg_match('#^https?://#i', $url) === 1;
    }

    public function baseType(string $type): string
    {
        $type = mb_strtolower(trim($type));
        if (preg_match('/^(phone|email)_\d+$/', $type, $m) === 1) {
            return $m[1];
        }

        return $type;
    }

    public function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function escAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
