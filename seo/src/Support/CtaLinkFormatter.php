<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

final class CtaLinkFormatter
{
    /** @var list<string> */
    public const PLAIN_TEXT_TYPES = ['address', 'working_hours'];

    public static function isPlainTextType(string $type): bool
    {
        return in_array(mb_strtolower(trim($type)), self::PLAIN_TEXT_TYPES, true);
    }

    public static function format(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return match ($type) {
            'phone', 'hotline' => self::formatPhone($value),
            'email' => self::formatEmail($value),
            'zalo' => self::formatZalo($value),
            'address' => self::formatAddress($value),
            'website' => self::formatWebsite($value),
            'facebook' => self::formatFacebook($value),
            'working_hours' => $value,
            default => $value,
        };
    }

    private static function formatPhone(string $value): string
    {
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';

        return $digits !== '' ? 'tel:' . $digits : '';
    }

    private static function formatEmail(string $value): string
    {
        if (str_starts_with(strtolower($value), 'mailto:')) {
            return $value;
        }

        return 'mailto:' . $value;
    }

    private static function formatZalo(string $value): string
    {
        if (preg_match('~^https?://~i', $value) === 1) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits !== '' ? 'https://zalo.me/' . $digits : $value;
    }

    private static function formatAddress(string $value): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($value);
    }

    private static function formatWebsite(string $value): string
    {
        if (preg_match('~^https?://~i', $value) === 1 || str_starts_with($value, '//')) {
            return str_starts_with($value, '//') ? 'https:' . $value : $value;
        }

        return 'https://' . ltrim($value, '/');
    }

    private static function formatFacebook(string $value): string
    {
        if (preg_match('~^https?://~i', $value) === 1 || str_starts_with($value, '//')) {
            return str_starts_with($value, '//') ? 'https:' . $value : $value;
        }

        return 'https://facebook.com/' . ltrim($value, '@/');
    }
}
