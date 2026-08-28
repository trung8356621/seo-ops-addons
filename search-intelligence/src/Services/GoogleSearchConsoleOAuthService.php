<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class GoogleSearchConsoleOAuthService
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/userinfo.email';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const SESSION_KEY = 'gsc_oauth_pending';

    public function redirectUri(): string
    {
        if (Route::has('seo.gsc.oauth.callback')) {
            return route('seo.gsc.oauth.callback', absolute: true);
        }

        return (string) config('services.google_search_console.redirect');
    }

    public function isConfiguredForConnection(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        $app = $this->resolveOAuthApp($connection);

        return $app['client_id'] !== '' && $app['client_secret'] !== '' && $app['redirect_uri'] !== '';
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    public function resolveOAuthApp(SeoGscMasterConnection $connection): array
    {
        return [
            'client_id' => trim((string) ($connection->oauth_client_id ?? '')),
            'client_secret' => trim((string) ($connection->oauth_client_secret ?? '')),
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    /**
     * @return array{state: string, context: array<string, mixed>}
     */
    public function beginAuthorization(
        string $connectionHash,
        int $userId,
        int $accountOwnerId,
        string $returnUrl,
        bool $forceConsent = false,
        ?int $connectionId = null,
    ): array {
        $state = Str::random(64);

        $context = [
            'state' => $state,
            'user_id' => $userId,
            'account_owner_id' => $accountOwnerId,
            'connection_hash' => $connectionHash,
            'return_url' => $returnUrl,
            'connection_id' => $connectionId,
            'force_consent' => $forceConsent,
            'created_at' => now()->toIso8601String(),
        ];

        session([self::SESSION_KEY => $context]);

        return [
            'state' => $state,
            'context' => $context,
        ];
    }

    public function buildAuthorizationUrl(
        SeoGscMasterConnection $connection,
        string $state,
        bool $forceConsent = false,
    ): string {
        $app = $this->resolveOAuthApp($connection);

        $query = [
            'client_id' => $app['client_id'],
            'redirect_uri' => $app['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return self::AUTH_URL.'?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumePendingContext(string $state): ?array
    {
        $pending = session(self::SESSION_KEY);
        if (! is_array($pending)) {
            return null;
        }

        $storedState = (string) ($pending['state'] ?? '');
        if ($storedState === '' || ! hash_equals($storedState, $state)) {
            return null;
        }

        session()->forget(self::SESSION_KEY);

        return $pending;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(SeoGscMasterConnection $connection, string $code): array
    {
        $app = $this->resolveOAuthApp($connection);

        $response = Http::asForm()
            ->timeout(25)
            ->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'redirect_uri' => $app['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->sanitizeMessage(
                (string) ($response->json('error_description') ?? $response->json('error') ?? 'token_exchange_failed'),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('token_exchange_invalid_response');
        }

        return $payload;
    }

    public function fetchAccountEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get(self::USERINFO_URL);

        if (! $response->successful()) {
            return null;
        }

        $email = trim((string) $response->json('email', ''));

        return $email !== '' ? $email : null;
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     */
    public function persistOAuthTokens(
        SeoGscMasterConnection $connection,
        array $tokenResponse,
        ?string $accountEmail = null,
    ): SeoGscMasterConnection {
        $credentials = is_array($connection->credentials) ? $connection->credentials : [];

        $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($accessToken !== '') {
            $credentials['access_token'] = $accessToken;
        }

        $refreshToken = trim((string) ($tokenResponse['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $credentials['refresh_token'] = $refreshToken;
        }

        $expiresIn = (int) ($tokenResponse['expires_in'] ?? 0);
        if ($expiresIn > 0) {
            $credentials['expires_at'] = now()->addSeconds($expiresIn)->toIso8601String();
        }

        $tokenType = trim((string) ($tokenResponse['token_type'] ?? ''));
        if ($tokenType !== '') {
            $credentials['token_type'] = $tokenType;
        }

        if ($credentials !== []) {
            $connection->credentials = $credentials;
        }

        if ($accountEmail !== null && $accountEmail !== '') {
            $connection->account_email = $accountEmail;
        }

        $connection->last_error = null;
        $connection->last_checked_at = now();
        $connection->status = app(GoogleSearchConsoleConnectionService::class)
            ->resolveEffectiveStatus($connection);
        $connection->save();

        return $connection;
    }

    public function disconnect(SeoGscMasterConnection $connection): void
    {
        $connection->credentials = null;
        $connection->account_email = null;
        $connection->status = 'not_configured';
        $connection->last_error = null;
        $connection->metadata = array_merge($connection->metadata ?? [], [
            'properties' => [],
        ]);
        $connection->last_checked_at = now();
        $connection->save();
    }

    public function shouldForceConsent(?SeoGscMasterConnection $connection): bool
    {
        if ($connection === null) {
            return true;
        }

        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return true;
        }

        return trim((string) ($credentials['refresh_token'] ?? '')) === '';
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: string|null}|null
     */
    public function refreshAccessToken(SeoGscMasterConnection $connection): ?array
    {
        $credentials = $connection->credentials;
        if (! is_array($credentials)) {
            return null;
        }

        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return null;
        }

        $app = $this->resolveOAuthApp($connection);

        $response = Http::asForm()
            ->timeout(25)
            ->post(self::TOKEN_URL, [
                'client_id' => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->sanitizeMessage(
                (string) ($response->json('error_description') ?? $response->json('error') ?? 'token_refresh_failed'),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('token_refresh_invalid_response');
        }

        $this->persistOAuthTokens($connection, $payload);

        $updated = $connection->fresh()?->credentials;
        if (! is_array($updated)) {
            return null;
        }

        return [
            'access_token' => (string) ($updated['access_token'] ?? ''),
            'refresh_token' => isset($updated['refresh_token']) ? (string) $updated['refresh_token'] : null,
            'expires_at' => isset($updated['expires_at']) ? (string) $updated['expires_at'] : null,
        ];
    }

    public function isAccessTokenExpired(array $credentials): bool
    {
        $expiresAt = trim((string) ($credentials['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }

        try {
            return now()->gte(\Carbon\Carbon::parse($expiresAt)->subSeconds(60));
        } catch (\Throwable) {
            return false;
        }
    }

    private function sanitizeMessage(string $message): string
    {
        $message = Str::limit(trim($message), 240, '');

        return Str::replaceMatches(
            '/(password|api[_ -]?key|secret|token|refresh_token|access_token|authorization_code|client_secret)\s*[:=]\s*\S+/i',
            '$1=[redacted]',
            $message,
        );
    }
}
