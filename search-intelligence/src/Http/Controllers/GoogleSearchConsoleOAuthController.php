<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleOAuthService;
use Omnichannel\Addons\SearchIntelligence\Services\GoogleSearchConsoleSyncService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class GoogleSearchConsoleOAuthController extends Controller
{
    public function redirect(
        Request $request,
        string $connection_hash,
        int $record,
        GoogleSearchConsoleOAuthService $oauth,
        GoogleSearchConsoleConnectionService $connections,
    ): RedirectResponse {
        $this->authorizeManagerMutation();

        if (! SeoConnectionContext::isValidHashFormat($connection_hash)) {
            abort(404);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $existing = $connections->resolveByIdForUser((int) $user->id, $record);
        if ($existing === null) {
            abort(404);
        }

        if (! $oauth->isConfiguredForConnection($existing)) {
            return $this->redirectWithError(
                $connection_hash,
                $record,
                __('seo-content-ai::filament.api_connections.gsc_oauth_app_not_configured'),
            );
        }

        $userId = (int) $user->id;
        $accountOwnerId = (int) (SeoAccessControl::accountOwnerId() ?? $userId);
        $forceConsent = $request->boolean('reconnect') || $oauth->shouldForceConsent($existing);

        $returnUrl = $this->resolveReturnUrl($connection_hash, $record);
        $pending = $oauth->beginAuthorization(
            connectionHash: $connection_hash,
            userId: $userId,
            accountOwnerId: $accountOwnerId,
            returnUrl: $returnUrl,
            forceConsent: $forceConsent,
            connectionId: $record,
        );

        return redirect()->away($oauth->buildAuthorizationUrl($existing, $pending['state'], $forceConsent));
    }

    public function callback(
        Request $request,
        GoogleSearchConsoleOAuthService $oauth,
        GoogleSearchConsoleConnectionService $connections,
        GoogleSearchConsoleSyncService $sync,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect('/seo')->with('gsc_oauth_error', __('seo-content-ai::filament.api_connections.gsc_oauth_unauthenticated'));
        }

        if ($request->filled('error')) {
            return $this->redirectFromCallbackContext(
                null,
                __('seo-content-ai::filament.api_connections.gsc_oauth_denied'),
            );
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return $this->redirectFromCallbackContext(
                null,
                __('seo-content-ai::filament.api_connections.gsc_oauth_invalid_request'),
            );
        }

        $context = $oauth->consumePendingContext($state);
        if ($context === null) {
            $pending = session('gsc_oauth_pending');

            return $this->redirectFromCallbackContext(
                is_array($pending) ? $pending : null,
                __('seo-content-ai::filament.api_connections.gsc_oauth_invalid_state'),
            );
        }

        if ((int) ($context['user_id'] ?? 0) !== (int) $user->id) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_context_mismatch'),
            );
        }

        $accountOwnerId = (int) (SeoAccessControl::accountOwnerId() ?? $user->id);
        if ((int) ($context['account_owner_id'] ?? 0) !== $accountOwnerId) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_context_mismatch'),
            );
        }

        $connectionHash = (string) ($context['connection_hash'] ?? '');
        if (! SeoConnectionContext::isValidHashFormat($connectionHash)) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_invalid_context'),
            );
        }

        SeoConnectionContext::rememberHash($connectionHash);

        if (! SeoAccessControl::canAccessManagerFeatures() || ! SeoAccessControl::canMutateInSeoPanel()) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.keyword.workspace_save_denied'),
            );
        }

        $connectionId = (int) ($context['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_invalid_context'),
            );
        }

        $connection = $this->resolveConnection($connections, $user, $context);

        if (! $oauth->isConfiguredForConnection($connection)) {
            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_app_not_configured'),
            );
        }

        try {
            $tokenResponse = $oauth->exchangeAuthorizationCode($connection, $code);
            $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('missing_access_token');
            }

            $accountEmail = $oauth->fetchAccountEmail($accessToken);
            $connection = $oauth->persistOAuthTokens($connection, $tokenResponse, $accountEmail);

            $testResult = $connections->testConnection($connection->fresh() ?? $connection);
            if (! $testResult['ok']) {
                return $this->redirectFromCallbackContext(
                    $context,
                    $testResult['message'],
                    false,
                );
            }

            $properties = $sync->listProperties($connection->fresh() ?? $connection);
            $freshConnection = $connection->fresh() ?? $connection;
            $freshConnection->metadata = array_merge($freshConnection->metadata ?? [], [
                'properties' => $properties,
            ]);
            $freshConnection->last_synced_at = now();
            $freshConnection->save();

            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_success'),
                true,
            );
        } catch (\Throwable) {
            Log::warning('GSC OAuth callback failed', [
                'user_id' => $user->id,
                'connection_hash' => $connectionHash,
                'message' => 'oauth_callback_failed',
            ]);

            return $this->redirectFromCallbackContext(
                $context,
                __('seo-content-ai::filament.api_connections.gsc_oauth_failed'),
            );
        }
    }

    private function authorizeManagerMutation(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            abort(403, __('seo-content-ai::filament.keyword.workspace_save_denied'));
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            abort(403, __('seo-content-ai::filament.keyword.workspace_save_denied'));
        }
    }

    private function resolveReturnUrl(string $connectionHash, int $recordId): string
    {
        return '/seo/'.$connectionHash.'/settings/api/google-search-console/'.$recordId.'/edit';
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function redirectFromCallbackContext(
        ?array $context,
        string $message,
        bool $success = false,
    ): RedirectResponse {
        $target = $this->resolveTargetUrlFromContext($context);

        $redirect = redirect()->to($target);

        if ($success) {
            session()->flash('gsc_oauth_success', $message);
        } else {
            session()->flash('gsc_oauth_error', $message);
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function resolveTargetUrlFromContext(?array $context): string
    {
        if (! is_array($context)) {
            return SeoConnectionContext::panelPath('settings/api');
        }

        $connectionHash = trim((string) ($context['connection_hash'] ?? ''));
        if (SeoConnectionContext::isValidHashFormat($connectionHash)) {
            SeoConnectionContext::rememberHash($connectionHash);
        }

        $connectionId = (int) ($context['connection_id'] ?? 0);
        if ($connectionId > 0) {
            return AiConnectionResource::gscEditUrl(
                $connectionId,
                SeoConnectionContext::isValidHashFormat($connectionHash) ? $connectionHash : null,
            );
        }

        $returnUrl = $this->normalizePanelReturnUrl((string) ($context['return_url'] ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        return SeoConnectionContext::panelPath('settings/api');
    }

    private function normalizePanelReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') {
            return '';
        }

        if (str_starts_with($returnUrl, '/seo/')) {
            return $returnUrl;
        }

        $parsedPath = parse_url($returnUrl, PHP_URL_PATH);

        return is_string($parsedPath) && str_starts_with($parsedPath, '/seo/')
            ? $parsedPath
            : '';
    }

    private function redirectWithError(string $connectionHash, int $recordId, string $message): RedirectResponse
    {
        session()->flash('gsc_oauth_error', $message);

        if (SeoConnectionContext::isValidHashFormat($connectionHash)) {
            SeoConnectionContext::rememberHash($connectionHash);
        }

        return redirect()->to(AiConnectionResource::gscEditUrl($recordId, $connectionHash));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveConnection(
        GoogleSearchConsoleConnectionService $connections,
        User $user,
        array $context,
    ): SeoGscMasterConnection {
        $connectionId = (int) ($context['connection_id'] ?? 0);
        if ($connectionId > 0) {
            $existing = $connections->resolveByIdForUser((int) $user->id, $connectionId);
            if ($existing instanceof SeoGscMasterConnection) {
                return $existing;
            }
        }

        return $connections->createForUser((int) $user->id, [
            'name' => 'Google Search Console',
        ]);
    }
}
