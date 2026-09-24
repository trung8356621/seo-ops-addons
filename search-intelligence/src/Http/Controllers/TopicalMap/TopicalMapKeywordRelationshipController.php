<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGraphPresenter;

/**
 * Authenticated JSON for Keyword Relationship visualization (Topical Map React app).
 *
 * Boundary: KeywordRelationshipGateway + KeywordRelationshipGraphPresenter only.
 * Graph is presented with all categories enabled so React can filter client-side.
 */
final class TopicalMapKeywordRelationshipController extends Controller
{
    /** @var array<string, bool> */
    private const ALL_CATEGORIES = [
        'topic' => true,
        'article' => true,
        'dna' => true,
        'related_keyword' => true,
        'gsc' => true,
        'internal_link' => true,
        'planning' => true,
    ];

    public function __construct(
        private readonly TopicalMapAccess $access,
        private readonly KeywordRelationshipGateway $gateway,
        private readonly KeywordRelationshipGraphPresenter $presenter,
    ) {}

    public function __invoke(Request $request, int $keyword): JsonResponse
    {
        $siteId = (int) $request->query('site_id', 0);
        if ($siteId <= 0) {
            $siteId = (int) $request->query('site', 0);
        }

        $this->access->assertCanAccessSite($siteId);

        $relationship = $this->gateway->forKeyword($siteId, $keyword);
        if ($relationship === null) {
            abort(404, 'Keyword not found.');
        }

        $data = $relationship->toArray();
        $graph = $this->presenter->present($relationship, self::ALL_CATEGORIES);

        return response()->json([
            'ok' => true,
            'relationship' => $data,
            'graph' => $graph,
        ]);
    }
}
