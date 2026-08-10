<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Http\Controllers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\Content\Services\ArticleHeadingAiGenerateService;
use Omnichannel\Addons\Content\Services\ArticleTocExtractionService;
use Omnichannel\Addons\Seo\Services\HeadingDuplicateCheckerService;
use Omnichannel\Addons\Seo\Services\HeadingDuplicateCheckService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * API Outline (TOC) cho React Editor:
 * - GET    /api/seo/articles/{article}/outline
 * - POST   /api/seo/articles/{article}/outline
 * - PUT    /api/seo/articles/{article}/outline/{heading}
 * - POST   /api/seo/articles/{article}/outline/{heading}/generate
 */
class ArticleOutlineController extends Controller
{
    public function __construct(
        private readonly ArticleTocExtractionService $tocExtraction,
        private readonly HeadingDuplicateCheckService $duplicateCheck,
        private readonly HeadingDuplicateCheckerService $duplicateChecker,
        private readonly ArticleHeadingAiGenerateService $aiGenerate,
    ) {}

    public function index(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $headings = $article->headings()->get();

        // Bài cũ chưa từng bóc tách -> bóc tách lần đầu ngay khi mở tab Outline.
        if ($headings->isEmpty()) {
            $this->tocExtraction->extractForArticle($article);
            $headings = $article->headings()->get();
        }

        return response()->json([
            'success' => true,
            'article' => [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
            ],
            'outline' => $this->buildTree($headings),
        ]);
    }

    public function refresh(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $validated = $request->validate([
            'html' => ['nullable', 'string'],
            'reextract' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('reextract')) {
            $html = trim((string) ($validated['html'] ?? ''));
            if ($html === '') {
                $html = $this->tocExtraction->resolveArticleContent($article);
            }

            $this->tocExtraction->extractAndStore((int) $article->id, $html);
        }

        $headings = $article->headings()->get();

        return response()->json([
            'success' => true,
            'article' => [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
            ],
            'outline' => $this->buildTree($headings),
        ]);
    }

    /**
     * Dò trùng toàn bộ dàn ý hiện tại với các bài khác trong site.
     * Chỉ chạy khi user bấm nút "Dò trùng lặp" hoặc AI workflow gọi sau khi sinh outline.
     */
    public function checkDuplicates(SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $headings = $article->headings()->get();

        $result = $this->duplicateChecker->check(
            $headings->mapWithKeys(
                fn (SeoArticleHeading $row): array => [(int) $row->id => [
                    'text' => (string) $row->heading_text,
                    'level' => (int) $row->level,
                ]],
            )->all(),
            (int) $article->site_id,
            (int) $article->id,
        );

        return response()->json([
            'success' => true,
            'has_duplicate' => $result['is_duplicate'],
            'duplicates' => $result['duplicates'],
        ]);
    }

    public function store(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $validated = $request->validate([
            'heading_text' => ['required', 'string', 'max:5000'],
            'level' => ['sometimes', 'integer', 'min:2', 'max:4'],
            'parent_id' => ['nullable', 'integer'],
            'after_heading_id' => ['nullable', 'integer'],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', $validated['heading_text']) ?? $validated['heading_text']);
        $text = Str::limit($text, 255, '');
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Heading không được để trống.',
            ], 422);
        }

        $level = (int) ($validated['level'] ?? 2);
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        $afterHeadingId = isset($validated['after_heading_id']) ? (int) $validated['after_heading_id'] : null;

        if ($parentId !== null) {
            $parent = SeoArticleHeading::query()
                ->where('article_id', $article->id)
                ->whereKey($parentId)
                ->first();

            if ($parent === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent heading không hợp lệ.',
                ], 422);
            }
        }

        $insertSortOrder = null;

        if ($afterHeadingId !== null) {
            $afterHeading = SeoArticleHeading::query()
                ->where('article_id', $article->id)
                ->whereKey($afterHeadingId)
                ->first();

            if ($afterHeading !== null) {
                $sorted = $article->headings()->orderBy('sort_order')->get();
                $afterIndex = $sorted->search(
                    static fn (SeoArticleHeading $row): bool => (int) $row->id === $afterHeadingId,
                );

                if ($afterIndex !== false) {
                    $afterLevel = (int) $afterHeading->level;
                    $insertAfterIndex = (int) $afterIndex;

                    for ($i = $afterIndex + 1; $i < $sorted->count(); $i++) {
                        /** @var SeoArticleHeading $candidate */
                        $candidate = $sorted[$i];
                        if ((int) $candidate->level <= $afterLevel) {
                            break;
                        }

                        $insertAfterIndex = $i;
                    }

                    $insertSortOrder = (int) $sorted[$insertAfterIndex]->sort_order + 1;

                    if ($level === 2) {
                        $parentId = null;
                    } elseif ($parentId === null) {
                        $parentId = $afterHeading->parent_id !== null ? (int) $afterHeading->parent_id : null;
                    }
                }
            }
        }

        if ($insertSortOrder === null) {
            $insertSortOrder = (int) ($article->headings()->max('sort_order') ?? -1) + 1;
        } else {
            $article->headings()
                ->where('sort_order', '>=', $insertSortOrder)
                ->increment('sort_order');
        }

        $heading = SeoArticleHeading::query()->create([
            'article_id' => (int) $article->id,
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
            'level' => $level,
            'sort_order' => $insertSortOrder,
            'parent_id' => $parentId,
        ]);

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
        ], 201);
    }

    public function update(Request $request, SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        $validated = $request->validate([
            'heading_text' => ['required', 'string', 'max:5000'],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', $validated['heading_text']) ?? $validated['heading_text']);
        $text = Str::limit($text, 255, '');
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Heading không được để trống.',
            ], 422);
        }

        $heading->update([
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
        ]);

        $duplicates = collect();
        try {
            $duplicates = $this->duplicateCheck
                ->checkExactMatch($heading->heading_slug, (int) $article->site_id, (int) $article->id, (int) $heading->level)
                ->map(fn (SeoArticleHeading $row): array => [
                    'heading_id' => (int) $row->id,
                    'article_id' => (int) $row->article_id,
                    'article_title' => (string) ($row->article?->title ?? ''),
                    'heading_text' => (string) $row->heading_text,
                ])
                ->values();
        } catch (\Throwable) {
            // Lưu heading đã thành công — dò trùng chỉ phụ, không làm fail request.
        }

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
            'duplicates' => $duplicates,
        ]);
    }

    public function destroy(SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        $headingId = (int) $heading->id;
        $heading->delete();

        return response()->json([
            'success' => true,
            'heading_id' => $headingId,
        ]);
    }

    public function generate(SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        try {
            $text = $this->aiGenerate->generateHeadingText($article, $heading);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $text = Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 255, '');
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'AI không sinh được heading hợp lệ.',
            ], 422);
        }

        $heading->update([
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
        ]);

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
        ]);
    }

    /**
     * @param  Collection<int, SeoArticleHeading>  $headings
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $headings): array
    {
        $nodes = [];
        foreach ($headings as $heading) {
            $nodes[(int) $heading->id] = $this->headingToArray($heading) + ['children' => []];
        }

        $tree = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function headingToArray(SeoArticleHeading $heading): array
    {
        return [
            'id' => (int) $heading->id,
            'article_id' => (int) $heading->article_id,
            'heading_text' => (string) $heading->heading_text,
            'heading_slug' => (string) $heading->heading_slug,
            'level' => (int) $heading->level,
            'sort_order' => (int) $heading->sort_order,
            'parent_id' => $heading->parent_id !== null ? (int) $heading->parent_id : null,
        ];
    }
}
