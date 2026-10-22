<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated streaming for Script release video_demo / thumbnail (not public).
 */
final class ScriptReleaseMediaController extends Controller
{
    public function __construct(
        private readonly ScriptReleaseMediaService $media,
        private readonly SocialAutomationAccess $access,
    ) {}

    public function show(Request $request, int $mediaId): StreamedResponse
    {
        $this->access->assertCanAccess();

        $row = $this->media->findReadyMedia($mediaId);
        abort_if($row === null, 404);

        $release = SaPackageRelease::query()->where('id', $row->release_id)->first();
        abort_if(! $release instanceof SaPackageRelease, 404);
        $package = SaPackage::query()->where('id', $release->package_id)->where('kind', 'script')->first();
        abort_if(! $package instanceof SaPackage, 404);

        $disk = Storage::disk($row->storage_disk ?: 'local');
        abort_unless($disk->exists($row->storage_path), 404);

        return $disk->response(
            $row->storage_path,
            basename($row->storage_path),
            [
                'Content-Type' => (string) $row->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
