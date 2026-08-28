<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Services;

use Omnichannel\Addons\Social\Enums\SocialPlatform;

/**
 * Stateless web share intent URLs — no Social Profile / OAuth / Electron.
 */
final class SocialShareUrlResolver
{
    /**
     * Fixed manual-share platforms (always shown when article URL exists).
     *
     * @return list<SocialPlatform>
     */
    public function manualSharePlatforms(): array
    {
        return [
            SocialPlatform::Facebook,
            SocialPlatform::LinkedIn,
            SocialPlatform::X,
        ];
    }

    public function shareIntent(SocialPlatform $platform, string $articleUrl, string $articleTitle = ''): ?string
    {
        $articleUrl = trim($articleUrl);
        if ($articleUrl === '') {
            return null;
        }

        $encodedUrl = rawurlencode($articleUrl);
        $articleTitle = trim($articleTitle);

        return match ($platform) {
            SocialPlatform::Facebook => 'https://www.facebook.com/sharer/sharer.php?u='.$encodedUrl,
            SocialPlatform::LinkedIn => 'https://www.linkedin.com/sharing/share-offsite/?url='.$encodedUrl,
            SocialPlatform::X => 'https://twitter.com/intent/tweet?url='.$encodedUrl
                .($articleTitle !== '' ? '&text='.rawurlencode($articleTitle) : ''),
            default => null,
        };
    }
}
