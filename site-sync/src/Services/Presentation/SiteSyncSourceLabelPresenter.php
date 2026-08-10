<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Presentation;

/**
 * Human-readable source chips for Domain / Ops UI.
 */
final class SiteSyncSourceLabelPresenter
{
    /**
     * @param  array<string, mixed>  $sources  from SiteSyncStatusPresenter capability_sources
     * @return list<array{key: string, label: string}>
     */
    public function chips(array $sources): array
    {
        $chips = [];
        $provider = (string) ($sources['provider'] ?? '');
        if ($provider === '' || $provider === 'none') {
            $chips[] = ['key' => 'provider', 'label' => 'SEO provider: Không phát hiện'];
        } else {
            $chips[] = ['key' => 'provider', 'label' => 'SEO provider: '.$this->providerLabel($provider)];
        }

        foreach (($sources['seo_score']['sources'] ?? []) as $scoreSrc) {
            $chips[] = [
                'key' => 'seo_score',
                'label' => 'SEO score: '.$this->scoreLabel((string) $scoreSrc, $provider),
            ];
        }

        $kw = (string) ($sources['keyword']['provider'] ?? '');
        if ($kw !== '') {
            $chips[] = [
                'key' => 'keyword',
                'label' => 'Keyword: '.$this->keywordLabel($kw, ! empty($sources['keyword']['workspace_fallback'])),
            ];
        }

        if (! empty($sources['http_404']['source'])) {
            $chips[] = [
                'key' => 'http_404',
                'label' => '404: '.(string) $sources['http_404']['source'],
            ];
        }

        return $chips;
    }

    public function providerLabel(string $provider): string
    {
        return match ($provider) {
            'rank_math' => 'Rank Math',
            'yoast' => 'Yoast SEO',
            'aioseo' => 'All in One SEO',
            'none', '' => 'Không phát hiện',
            'workspace' => 'SEO Workspace',
            default => $provider,
        };
    }

    public function scoreLabel(string $source, string $provider): string
    {
        if (in_array($source, ['workspace', 'workspace_fallback'], true)) {
            return 'SEO Workspace';
        }
        if ($source === 'unavailable') {
            return 'SEO Workspace';
        }

        return $this->providerLabel($source !== '' ? $source : $provider);
    }

    public function keywordLabel(string $provider, bool $workspaceFallback): string
    {
        if ($workspaceFallback || in_array($provider, ['none', 'unavailable', 'workspace'], true)) {
            return 'SEO Workspace fallback';
        }

        return $this->providerLabel($provider);
    }
}
