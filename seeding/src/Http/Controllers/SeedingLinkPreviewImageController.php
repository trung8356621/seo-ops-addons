<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Omnichannel\Addons\Seeding\Services\SeedingLinkPreviewImageCache;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SeedingLinkPreviewImageController
{
    public function __construct(
        private readonly SeedingAccess $access,
        private readonly SeedingLinkPreviewImageCache $images,
    ) {}

    public function __invoke(string $hash): SymfonyResponse
    {
        $this->access->assertCanAccess(request()->user());

        $cached = $this->images->readCached($hash);
        if ($cached === null) {
            abort(404);
        }

        return response($cached['body'], 200, [
            'Content-Type' => $cached['content_type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
