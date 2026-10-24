<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseMedia;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Script Registry catalog for bidirectional sync.
 */
final class ScriptCatalogService
{
    /**
     * @return list<array{
     *     script_id: string,
     *     package_key: string,
     *     version: string,
     *     content_digest: string,
     *     execution_digest: string|null,
     *     assets_complete: bool,
     *     name: string,
     *     lineage: list<string>
     * }>
     */
    public function listScripts(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $out = [];
        $packages = SaPackage::query()->where('kind', 'script')->orderBy('package_key')->get();
        foreach ($packages as $package) {
            $latest = SaPackageRelease::query()
                ->where('package_id', $package->id)
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();
            if (! $latest instanceof SaPackageRelease) {
                continue;
            }
            $manifest = is_array($latest->manifest) ? $latest->manifest : [];
            $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
            $assetsComplete = $this->assetsComplete($assets);
            $lineage = is_array($manifest['lineage'] ?? null)
                ? array_values(array_filter(array_map('strval', $manifest['lineage'])))
                : [];
            $mediaKinds = Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_media')
                ? SaScriptReleaseMedia::query()->where('release_id', $latest->id)
                    ->where('status', SaScriptReleaseMedia::STATUS_READY)->pluck('kind')->all()
                : [];
            $demoReady = in_array(SaScriptReleaseMedia::KIND_VIDEO_DEMO, $mediaKinds, true)
                && in_array(SaScriptReleaseMedia::KIND_THUMBNAIL, $mediaKinds, true);

            $out[] = [
                'script_id' => (string) ($manifest['script_id'] ?? $package->package_key),
                'package_key' => (string) $package->package_key,
                'version' => (string) $latest->version,
                'content_digest' => (string) $latest->content_digest,
                'execution_digest' => $latest->execution_digest
                    ? (string) $latest->execution_digest
                    : ScriptExecutionDigest::fromManifest($manifest),
                'assets_complete' => $assetsComplete,
                'name' => (string) $package->name,
                'lineage' => $lineage,
                'incomplete' => ! $assetsComplete,
                'demo_status' => $demoReady ? 'ready' : 'missing',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    private function assetsComplete(array $assets): bool
    {
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                return false;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            $sha = strtolower(trim((string) ($asset['sha256'] ?? '')));
            if ($path === '' || ! PackageDigest::isValidHex($sha)) {
                return false;
            }
        }

        return true;
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages');
        } catch (Throwable) {
            return false;
        }
    }
}
