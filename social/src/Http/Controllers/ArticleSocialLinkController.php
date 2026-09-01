<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Social\Http\Requests\ArticleSocialLinksStoreRequest;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArticleSocialLinkController extends Controller
{
    public function __construct(
        private readonly ArticleSocialLinkService $socialLinks,
    ) {}

    public function index(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless($request->user() instanceof User, 403);

        return response()->json([
            'ok' => true,
            'links' => $this->socialLinks->getLinksForArticle((int) $article->getKey()),
        ]);
    }

    public function store(ArticleSocialLinksStoreRequest $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $result = $this->socialLinks->saveBatch(
            $article,
            $request->links(),
            ArticleSocialLinkService::SOURCE_API,
            $request->integrationKey(),
            $request->user()?->id !== null ? (int) $request->user()->id : null,
        );

        return response()->json([
            'ok' => true,
            'result' => [
                'saved' => (int) ($result['saved'] ?? 0),
                'duplicate' => (int) ($result['duplicate'] ?? 0),
                'unsupported' => (int) ($result['unsupported'] ?? 0),
                'invalid' => (int) ($result['invalid'] ?? 0),
            ],
        ]);
    }
}
