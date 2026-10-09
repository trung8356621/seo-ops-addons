<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\CtaAutomation\CtaAutomationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Improve / regenerate CTA preview. Apply returns HTML for the editor save lifecycle.
 */
final class ArticleEditorCtaAutomationController extends Controller
{
    public function __construct(
        private readonly CtaAutomationService $automation,
    ) {}

    public function preview(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $html = (string) $request->input('editor_html', '');
        if (trim($html) === '') {
            $html = (string) ($article->body ?? '');
        }
        $mode = (string) $request->input('mode', 'improve');
        $result = $this->automation->preview($article, $html, $mode);
        $status = ($result['success'] ?? false) === true ? 200 : 422;

        return response()->json($result, $status);
    }

    public function apply(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }
        $html = (string) $request->input('editor_html', '');
        $token = trim((string) $request->input('preview_token', ''));
        $approved = $request->input('approved_ids', []);
        if (! is_array($approved)) {
            $approved = [];
        }
        $approved = array_values(array_map(static fn (mixed $id): string => (string) $id, $approved));
        $result = $this->automation->apply($article, $html, $token, $approved);
        if (($result['success'] ?? false) !== true) {
            $code = ($result['status'] ?? '') === 'stale_preview' ? 409 : 422;

            return response()->json($result, $code);
        }

        return response()->json([
            'success' => true,
            'status' => 'applied',
            'html' => $result['html'],
            'document_version' => $request->input('document_version'),
            'applied' => $result['applied'] ?? [],
        ]);
    }
}
