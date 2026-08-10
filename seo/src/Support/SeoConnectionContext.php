<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\URL;

final class SeoConnectionContext
{
    private static ?SeoDatabaseConnection $current = null;

    public static function set(SeoDatabaseConnection $connection): void
    {
        self::$current = $connection;
        session(['seo_current_connection_hash' => $connection->hash_id]);
        self::applyUrlDefaults((string) $connection->hash_id);
    }

    public static function applyUrlDefaults(?string $hash = null): void
    {
        $hash ??= self::hash();

        if ($hash !== null && self::isValidHashFormat($hash)) {
            URL::defaults(['connection_hash' => $hash]);
        }
    }

    /**
     * Resolve connection_hash for URL generation (login, getUrl, Livewire).
     * Order: explicit route → context/session → path → Referer (Livewire).
     */
    public static function resolveHashFromRequest(?\Illuminate\Http\Request $request = null): ?string
    {
        $request ??= request();

        $routeHash = $request->route('connection_hash');
        if (is_string($routeHash) && self::isValidHashFormat($routeHash)) {
            return $routeHash;
        }

        $current = self::hash();
        if ($current !== null) {
            return $current;
        }

        $path = trim((string) $request->path(), '/');
        if (preg_match('#^seo/([a-zA-Z0-9]{32,64})(?:/|$)#', $path, $matches) === 1) {
            return $matches[1];
        }

        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && preg_match('#/seo/([a-zA-Z0-9]{32,64})(?:/|\\?|$)#', $referer, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Apply URL defaults from the current request when possible.
     */
    public static function applyUrlDefaultsFromRequest(?\Illuminate\Http\Request $request = null): ?string
    {
        $hash = self::resolveHashFromRequest($request);
        if ($hash !== null) {
            self::applyUrlDefaults($hash);
            self::rememberHash($hash);
        }

        return $hash;
    }

    public static function rememberHash(?string $hash = null): void
    {
        $hash ??= self::hash();

        if ($hash === null || ! self::isValidHashFormat($hash)) {
            return;
        }

        session(['seo_current_connection_hash' => $hash]);
        self::applyUrlDefaults($hash);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function mergePanelRouteParameters(array $parameters = []): array
    {
        if (isset($parameters['connection_hash']) && self::isValidHashFormat((string) $parameters['connection_hash'])) {
            return $parameters;
        }

        $hash = self::hash();
        if ($hash === null) {
            $routeHash = request()->route('connection_hash');
            if (is_string($routeHash) && self::isValidHashFormat($routeHash)) {
                $hash = $routeHash;
            }
        }

        if ($hash === null) {
            $path = trim((string) request()->path(), '/');
            if (preg_match('#^seo/([a-zA-Z0-9]{32,64})(?:/|$)#', $path, $matches) === 1) {
                $hash = $matches[1];
            }
        }

        if ($hash !== null) {
            $parameters['connection_hash'] = $hash;
        }

        return $parameters;
    }

    public static function current(): ?SeoDatabaseConnection
    {
        return self::$current;
    }

    public static function hash(): ?string
    {
        $hash = self::$current?->hash_id;

        if (is_string($hash) && self::isValidHashFormat($hash)) {
            return $hash;
        }

        $sessionHash = session('seo_current_connection_hash');

        return is_string($sessionHash) && self::isValidHashFormat($sessionHash)
            ? $sessionHash
            : null;
    }

    public static function isValidHashFormat(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9]{32,64}$/', $hash);
    }

    public static function panelPath(string $path = ''): string
    {
        $hash = self::hash();
        $path = ltrim($path, '/');

        if ($hash === null) {
            return $path === '' ? '/seo' : '/seo/'.$path;
        }

        return $path === '' ? '/seo/'.$hash : '/seo/'.$hash.'/'.$path;
    }

    public static function panelUrl(string $path = ''): string
    {
        return url(self::panelPath($path));
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
