<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry;
use Omnichannel\Addons\Publishing\Extension\Registry\PublisherRegistry;
use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * Resolve publisher by site publisher_key / seo_platform — fail closed, no silent WP fallback.
 */
final class PublisherResolver
{
    public function __construct(
        private readonly ContentPublisherRegistry $contentPublishers,
        private readonly PublisherRegistry $driverRegistry,
        private readonly ExtensionRegistry $extensions,
        private readonly ExtensionStateStore $states,
    ) {}

    public function resolveForSiteId(int $siteId): ContentPublisher
    {
        $key = $this->publisherKeyForSite($siteId);
        if ($key === '') {
            RuntimeLogger::warning('publisher_resolve_failed', [
                'result_code' => PublisherResolutionException::NOT_CONFIGURED,
                'site_id' => $siteId,
            ]);
            throw PublisherResolutionException::notConfigured('Site #'.$siteId.' missing publisher_key / seo_platform.');
        }

        return $this->resolveByKey($key, $siteId);
    }

    public function resolveByKey(string $key, ?int $siteId = null): ContentPublisher
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            throw PublisherResolutionException::notConfigured();
        }

        $extensionId = $key;
        if ($this->extensions->find($extensionId) !== null && ! $this->states->isEnabled($extensionId)) {
            RuntimeLogger::warning('publisher_resolve_failed', [
                'result_code' => PublisherResolutionException::DISABLED,
                'publisher_key' => $key,
                'extension_id' => $extensionId,
                'site_id' => $siteId,
            ]);
            throw PublisherResolutionException::disabled($extensionId);
        }

        $publisher = $this->contentPublishers->get($key);
        if (! $publisher instanceof ContentPublisher) {
            RuntimeLogger::warning('publisher_resolve_failed', [
                'result_code' => PublisherResolutionException::NOT_REGISTERED,
                'publisher_key' => $key,
                'site_id' => $siteId,
            ]);
            throw PublisherResolutionException::notRegistered($key);
        }

        $driver = $this->driverRegistry->get($key);
        if ($driver !== null) {
            $health = $driver->health();
            if (! ($health['ok'] ?? false)) {
                RuntimeLogger::warning('publisher_resolve_failed', [
                    'result_code' => PublisherResolutionException::UNHEALTHY,
                    'publisher_key' => $key,
                    'site_id' => $siteId,
                    'message' => (string) ($health['message'] ?? ''),
                ]);
                throw PublisherResolutionException::unhealthy($key, (string) ($health['message'] ?? 'unknown'));
            }
        }

        return $publisher;
    }

    public function publisherKeyForSite(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            return '';
        }

        $explicit = strtolower(trim((string) ($site->getMeta('seo_publisher_key') ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $platform = strtolower(trim((string) ($site->getMeta('seo_platform') ?? '')));
        if ($platform === 'wordpress') {
            return 'wordpress';
        }

        // custom / unknown — require explicit publisher_key (no silent wordpress)
        return $platform !== '' && $platform !== 'custom' ? $platform : '';
    }
}
