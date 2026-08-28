<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Support;

use Omnichannel\Addons\Social\Enums\SocialPlatform;
use Omnichannel\Addons\Social\Services\SocialShareUrlResolver;

/**
 * Stateless SocialShareActions presenter — Article URL → web share intents.
 * Independent of Social Profile / automation.
 *
 * @phpstan-type ShareAction array{
 *   key: string,
 *   platform: string,
 *   platform_label: string,
 *   compact_label: string,
 *   tooltip: string,
 *   mode: 'share_intent'|'copy_link',
 *   href: ?string,
 *   copy_url: ?string
 * }
 */
final class SocialShareActionsPresenter
{
    public function __construct(
        private readonly SocialShareUrlResolver $shareUrls,
    ) {}

    /**
     * @return array{actions: list<ShareAction>, can_share: bool}
     */
    public function present(string $url, string $title = '', bool $compact = true): array
    {
        $url = trim($url);
        $title = trim($title);

        if ($url === '' || ! $this->isPublicHttpUrl($url)) {
            return [
                'actions' => [],
                'can_share' => false,
            ];
        }

        $actions = [];
        foreach ($this->shareUrls->manualSharePlatforms() as $platform) {
            $href = $this->shareUrls->shareIntent($platform, $url, $title);
            if ($href === null) {
                continue;
            }

            $actions[] = [
                'key' => $platform->value,
                'platform' => $platform->value,
                'platform_label' => $platform->label(),
                'compact_label' => $platform->compactLabel(),
                'tooltip' => $this->shareTooltip($platform),
                'mode' => 'share_intent',
                'href' => $href,
                'copy_url' => null,
            ];
        }

        $actions[] = [
            'key' => 'copy',
            'platform' => 'copy',
            'platform_label' => 'Copy link',
            'compact_label' => '🔗',
            'tooltip' => $this->copyTooltip(),
            'mode' => 'copy_link',
            'href' => null,
            'copy_url' => $url,
        ];

        return [
            'actions' => $actions,
            'can_share' => true,
        ];
    }

    private function isPublicHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private function shareTooltip(SocialPlatform $platform): string
    {
        $fallback = 'Chia sẻ '.$platform->label();
        if (! function_exists('__')) {
            return $fallback;
        }

        try {
            $translated = (string) __('seo-content-ai::filament.social.share_tooltip', [
                'platform' => $platform->label(),
            ]);

            return ($translated !== '' && ! str_starts_with($translated, 'seo-content-ai::'))
                ? $translated
                : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function copyTooltip(): string
    {
        $fallback = 'Sao chép liên kết';
        if (! function_exists('__')) {
            return $fallback;
        }

        try {
            $translated = (string) __('seo-content-ai::filament.social.copy_link_tooltip');

            return ($translated !== '' && ! str_starts_with($translated, 'seo-content-ai::'))
                ? $translated
                : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
