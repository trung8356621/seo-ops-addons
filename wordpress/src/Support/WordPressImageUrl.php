<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

final class WordPressImageUrl
{
    /** WordPress generated size suffix before extension, e.g. -480x393.jpg */
    private const SIZE_SUFFIX_PATTERN = '/-(\d+)x(\d+)(?=\.(jpe?g|png|gif|webp)$)/i';

    public static function isLocalSeoMediaSrc(string $src): bool
    {
        return str_contains(strtolower($src), '/storage/uploads/seo_media/');
    }

    public static function isScaledVariant(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || self::isLocalSeoMediaSrc($url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' && preg_match(self::SIZE_SUFFIX_PATTERN, $path) === 1;
    }

    public static function toFullSize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || self::isLocalSeoMediaSrc($url)) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return preg_replace(self::SIZE_SUFFIX_PATTERN, '', $url) ?? $url;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return $url;
        }

        $fullPath = preg_replace(self::SIZE_SUFFIX_PATTERN, '', $path);
        if (! is_string($fullPath) || $fullPath === $path) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $pass = ($user !== '' || $pass !== '') ? $pass . '@' : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($host !== '') {
            return $scheme . $user . $pass . $host . $port . $fullPath . $query . $fragment;
        }

        return $fullPath . $query . $fragment;
    }

    public static function slugFromUrl(string $src): string
    {
        $full = self::toFullSize($src);
        $path = (string) parse_url($full, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $filename = basename($path);

        return pathinfo($filename, PATHINFO_FILENAME) ?: '';
    }

    /**
     * URL nhỏ hơn cho lưới chọn ảnh (giữ nguyên nếu đã là biến thể -WxH).
     */
    public static function toPreviewSize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || self::isLocalSeoMediaSrc($url) || self::isScaledVariant($url)) {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return $url;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return $url;
        }

        $basePath = substr($path, 0, -strlen($extension) - 1);
        $previewPath = $basePath . '-300x300.' . $extension;

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $previewPath;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $previewPath . $query . $fragment;
    }
}
