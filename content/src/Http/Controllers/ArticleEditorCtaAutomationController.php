<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\CtaAutomation\CtaAutomationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Legacy CTA Automation HTTP surface. Improve/apply retired — use Article Editor CTA Detector.
 */
final class ArticleEditorCtaAutomationController extends Controller
{
    public function __construct(
        private readonly CtaAutomationService $automation,
    ) {}

    public function preview(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        // Retired: AI improve/regenerate. Use Article Editor CTA Detector (client-side scan/wrap/delete).
        return response()->json([
            'success' => false,
            'status' => 'deprecated',
            'message' => 'CTA Automation improve/regenerate is retired. Use CTA Detector in the Article Editor.',
        ], 410);
    }

    public function apply(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => false,
            'status' => 'deprecated',
            'message' => 'CTA Automation apply is retired. Use CTA Detector in the Article Editor.',
        ], 410);
    }

    public function latest(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $fingerprint = trim((string) $request->query('source_fingerprint', ''));
        $run = $this->automation->latestRun($article);

        return response()->json([
            'success' => true,
            'run' => $run ? $this->automation->runPayload($run, null, $fingerprint !== '' ? $fingerprint : null) : null,
            'runs' => array_map(
                fn ($row) => $this->automation->runSummary($row, null, $fingerprint !== '' ? $fingerprint : null),
                $this->automation->recentRuns($article),
            ),
        ]);
    }

    public function show(Request $request, SeoArticle $article, string $run): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $found = $this->automation->findRun($article, $run);
        if ($found === null) {
            return response()->json(['success' => false, 'message' => 'CTA run was not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'run' => $this->automation->runPayload($found, (string) $request->input('editor_html', '') ?: null),
        ]);
    }

    public function selections(Request $request, SeoArticle $article, string $run): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => false,
            'status' => 'deprecated',
            'message' => 'CTA Automation selections are retired.',
        ], 410);
    }

    public function confirm(Request $request, SeoArticle $article, string $run): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return response()->json([
            'success' => false,
            'status' => 'deprecated',
            'message' => 'CTA Automation confirm is retired.',
        ], 410);
    }
}
