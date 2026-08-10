<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Http\Controllers;

use Omnichannel\Addons\WordPress\Services\WordPressMediaRenameService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WordPressMediaRenameController extends Controller
{
    public function __construct(
        private readonly WordPressMediaRenameService $rename,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        abort_unless($this->rename->canRenameWordPressMedia(), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'attachment_id' => ['required', 'integer', 'min:1'],
            'old_url' => ['nullable', 'string', 'max:2048'],
            'proposed_slug' => ['nullable', 'string', 'max:200'],
        ]);

        $site = Site::query()->findOrFail((int) $validated['site_id']);
        abort_unless(SeoAccessControl::canAccessSite((int) $site->id), 403);

        $result = $this->rename->preview(
            $site,
            (int) $validated['attachment_id'],
            (string) ($validated['old_url'] ?? ''),
            (string) ($validated['proposed_slug'] ?? ''),
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function rename(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        abort_unless($this->rename->canRenameWordPressMedia($actor), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'attachment_id' => ['required', 'integer', 'min:1'],
            'new_slug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i', 'max:180'],
            'old_url' => ['nullable', 'string', 'max:2048'],
            'acknowledge_url_change' => ['required', 'boolean'],
            'confirmation_phrase' => ['required', 'string', 'max:32'],
            'source_action' => ['nullable', 'string', 'in:article_editor,media_library'],
            'article_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $site = Site::query()->findOrFail((int) $validated['site_id']);
        abort_unless(SeoAccessControl::canAccessSite((int) $site->id), 403);

        $result = $this->rename->renameExplicit($site, $validated, $actor);
        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
