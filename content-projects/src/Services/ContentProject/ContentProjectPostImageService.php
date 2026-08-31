<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generate and insert post images during Content Project Run (post/article only).
 */
final class ContentProjectPostImageService
{
    public const GENERATION_SOURCE = 'content_project_run';

    public function __construct(
        private readonly ContentProjectPostSectionAnalyzer $sectionAnalyzer,
        private readonly ArticleEditorMediaAiService $mediaAi,
    ) {}

    /**
     * @return array{success: int, failed: int, skipped: int, errors: list<string>}
     */
    public function generateForRun(
        SeoArticle $article,
        SeoProjectRun $run,
        int $runItemId = 0,
    ): array {
        $article->refresh();
        $body = trim((string) ($article->body ?? ''));
        if ($body === '') {
            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        }

        $sections = $this->sectionAnalyzer->eligibleSections($body);
        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $html = $body;

        foreach ($sections as $section) {
            $blockId = $this->editorBlockId((int) $run->id, (string) $section['key']);

            if ($this->sectionHasRunMedia($article, $blockId, (int) $run->id)) {
                $stats['skipped']++;

                continue;
            }

            try {
                $selectionText = Str::limit(strip_tags((string) $section['content_html']), 1200, '');
                $selectionHtml = (string) $section['content_html'];
                $userBrief = 'Tạo ảnh minh họa cho section: '.(string) $section['heading'];

                $placeholder = $this->mediaAi->generateImageBlocking(
                    $article,
                    $selectionText,
                    $selectionHtml,
                    $userBrief,
                    $blockId,
                    'editor',
                );

                $mediaId = (int) ($placeholder['seo_media_id'] ?? 0);
                if ($mediaId <= 0) {
                    throw new \RuntimeException('Không tạo được placeholder media.');
                }

                $media = $this->tagRunMedia($mediaId, (int) $run->id, $runItemId, (string) $section['key']);
                $completed = $media->fresh() ?? $media;
                $url = trim((string) ($completed->url ?? ''));
                if ($url === '' || str_contains($url, 'placeholder-loading')) {
                    throw new \RuntimeException('Ảnh chưa hoàn thành sau khi generate.');
                }

                $html = $this->insertImageAfterHeading($html, (string) $section['heading_html'], $completed);
                $stats['success']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = (string) $section['heading'].': '.$e->getMessage();
                Log::warning('content_project.post_image.failed', [
                    'run_id' => (int) $run->id,
                    'article_id' => (int) $article->id,
                    'section' => $section['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($html !== $body) {
            try {
                app(ArticleEditorSessionService::class)
                    ->assertBodyRewriteAllowed($article, 'content_project_post_image');
            } catch (ArticleEditorSessionException $exception) {
                Log::warning('content_project.post_image.body_blocked_by_editor_session', [
                    'run_id' => (int) $run->id,
                    'article_id' => (int) $article->id,
                    'error' => $exception->errorCode,
                ]);
                $stats['failed']++;
                $stats['errors'][] = 'Editor session active — skipped body image insert: '.$exception->getMessage();

                return $stats;
            }

            $article->update(['body' => $html]);
            try {
                $writer = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class);
                $writer->invalidateForLegacyBodyWrite($article, 'content_project_post_image');
                if ($article->isDirty('editor_document_status')) {
                    $article->save();
                }
            } catch (\Throwable) {
                // best-effort
            }
        }

        return $stats;
    }

    public function editorBlockId(int $runId, string $sectionKey): string
    {
        return Str::limit('cpr-'.$runId.'-'.$sectionKey, 64, '');
    }

    private function sectionHasRunMedia(SeoArticle $article, string $blockId, int $runId): bool
    {
        return SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->where('editor_block_id', $blockId)
            ->whereIn('status', ['processing', 'completed'])
            ->exists();
    }

    private function tagRunMedia(int $mediaId, int $runId, int $runItemId, string $sectionKey): SeoMedia
    {
        $media = SeoMedia::query()->findOrFail($mediaId);
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $variables['generation_source'] = self::GENERATION_SOURCE;
        $variables['content_project_run_id'] = $runId;
        $variables['content_project_run_item_id'] = $runItemId > 0 ? $runItemId : null;
        $variables['section_key'] = $sectionKey;
        $media->update(['prompt_variables' => $variables]);

        return $media->fresh() ?? $media;
    }

    private function insertImageAfterHeading(string $html, string $headingHtml, SeoMedia $media): string
    {
        $url = trim((string) ($media->url ?? ''));
        if ($url === '') {
            return $html;
        }

        $mediaId = (int) $media->id;
        $imgHtml = '<figure class="wp-caption aligncenter"><img src="'
            .htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            .'" class="aligncenter" data-seo-media-id="'.$mediaId.'" alt="" /></figure>'."\n";

        $pos = mb_strpos($html, $headingHtml);
        if ($pos === false) {
            return $html;
        }

        $insertAt = $pos + mb_strlen($headingHtml);
        $afterHeading = mb_substr($html, $insertAt, 200);
        if ($afterHeading !== false && $this->sectionAnalyzerEligibleHasImage($afterHeading)) {
            return $html;
        }

        return mb_substr($html, 0, $insertAt)."\n".$imgHtml.mb_substr($html, $insertAt);
    }

    private function sectionAnalyzerEligibleHasImage(string $snippet): bool
    {
        return (bool) preg_match('/<img[\s>]/iu', $snippet);
    }
}
