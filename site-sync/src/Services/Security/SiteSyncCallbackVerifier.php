<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Security;

use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use App\Models\Site;
use App\Support\RuntimeLogger;

final class SiteSyncCallbackVerifier
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
    ) {}

    /**
     * @return array{ok: bool, message: string, code?: string}
     */
    public function verify(
        Site $site,
        string $rawBody,
        ?string $timestamp,
        ?string $nonce,
        ?string $signature,
    ): array {
        if (! $this->flags->requireSignedCallbacks()) {
            return ['ok' => true, 'message' => 'signature not required'];
        }

        if ($timestamp === null || $timestamp === '' || $nonce === null || $nonce === '' || $signature === null || $signature === '') {
            RuntimeLogger::warning('site_sync.security.signature_missing', ['site_id' => $site->id]);

            return ['ok' => false, 'message' => 'Missing signature headers.', 'code' => 'signature_missing'];
        }

        if (! ctype_digit($timestamp)) {
            return ['ok' => false, 'message' => 'Invalid timestamp.', 'code' => 'timestamp_invalid'];
        }

        $ts = (int) $timestamp;
        $skew = abs(time() - $ts);
        if ($skew > 300) {
            return ['ok' => false, 'message' => 'Timestamp outside tolerance.', 'code' => 'timestamp_skew'];
        }

        $secret = $this->secretForSite($site);
        $expected = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            RuntimeLogger::warning('site_sync.security.signature_failed', [
                'site_id' => $site->id,
                'nonce' => mb_substr($nonce, 0, 8),
            ]);

            return ['ok' => false, 'message' => 'Invalid signature.', 'code' => 'signature_invalid'];
        }

        $replayKey = 'site_sync_nonce:'.$site->id.':'.$nonce;
        if (cache()->has($replayKey)) {
            return ['ok' => false, 'message' => 'Replay detected.', 'code' => 'replay'];
        }
        cache()->put($replayKey, 1, now()->addMinutes(10));

        return ['ok' => true, 'message' => 'ok'];
    }

    public function secretForSite(Site $site): string
    {
        $site->loadMissing('metas');
        $configured = trim((string) ($site->getMeta('seo_sync_callback_secret') ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        $token = trim((string) ($site->getMeta('seo_read_token') ?? ''));

        return $token !== '' ? hash('sha256', 'omi-sync-'.$token) : 'omi-sync-unconfigured';
    }
}
