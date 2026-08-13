import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';

function readArticleIdFromBootstrap() {
    try {
        const raw = document.getElementById('seo-article-core-bootstrap')?.textContent?.trim();
        if (!raw) {
            return 0;
        }
        const data = JSON.parse(raw);

        return Number(data?.articleId ?? data?.article_id ?? 0) || 0;
    } catch {
        return 0;
    }
}

/**
 * @param {string} context
 * @param {{
 *   rendered?: string,
 *   prompt_id?: number,
 *   context_length?: number,
 *   rendered_length?: number,
 *   error?: string,
 * }} payload
 */
export function assertMergedAiMediaPrompt(context, payload) {
    const rendered = String(payload?.rendered ?? '').trim();
    const contextText = String(context ?? '').trim();
    const promptId = Number(payload?.prompt_id ?? 0) || 0;

    if (!rendered) {
        throw new Error(payload?.error || 'Không thể tạo prompt hoàn chỉnh.');
    }

    if (promptId <= 0) {
        throw new Error('Không tìm thấy prompt Typography/Image trong Settings.');
    }

    if (contextText !== '' && rendered === contextText) {
        throw new Error('Prompt đã merge không hợp lệ — chỉ có ngữ cảnh local.');
    }

    if (contextText !== '' && rendered.length <= contextText.length) {
        throw new Error('Prompt đã merge quá ngắn — thiếu template Settings.');
    }
}

/**
 * Resolve final merged image/video prompt via Settings template + article variables.
 *
 * @param {{
 *   userBrief?: string,
 *   selectionText?: string,
 *   mediaType?: 'image'|'video',
 *   target?: string,
 *   articleId?: number,
 * }} [options]
 * @returns {Promise<{
 *   rendered: string,
 *   promptId: number,
 *   promptName: string,
 *   source: string,
 *   mediaTarget: string,
 *   taskId: number|null,
 *   contextLength: number,
 *   renderedLength: number,
 *   error: string,
 * }>}
 */
export async function resolveAiMediaPrompt({
    userBrief = '',
    selectionText = '',
    mediaType = 'image',
    target = 'editor',
    articleId = readArticleIdFromBootstrap(),
} = {}) {
    const brief = String(userBrief ?? '').trim();
    if (brief === '') {
        throw new Error('Thiếu ngữ cảnh AI Media.');
    }

    const id = Number(articleId) || 0;
    if (id <= 0) {
        throw new Error('Không xác định được bài viết cho preview prompt.');
    }

    const selection = String(selectionText ?? '').trim() || brief;
    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/editor/media-prompt-preview`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                user_brief: brief,
                selection_text: selection,
                media_type: mediaType,
                target,
            }),
        },
    );

    const payload = data && typeof data === 'object' ? (data.data ?? data) : {};
    const message = String(data?.message ?? payload?.error ?? '').trim();

    if (!response.ok || data?.success === false) {
        throw new Error(message || 'Không thể tạo prompt hoàn chỉnh.');
    }

    const normalized = {
        rendered: String(payload.rendered ?? '').trim(),
        promptId: Number(payload.prompt_id ?? 0) || 0,
        promptName: String(payload.prompt_name ?? ''),
        source: String(payload.source ?? ''),
        mediaTarget: String(payload.media_target ?? target),
        taskId: payload.task_id != null ? Number(payload.task_id) : null,
        contextLength: Number(payload.context_length ?? brief.length) || brief.length,
        renderedLength: Number(payload.rendered_length ?? String(payload.rendered ?? '').length) || 0,
        error: String(payload.error ?? ''),
    };

    assertMergedAiMediaPrompt(brief, {
        rendered: normalized.rendered,
        prompt_id: normalized.promptId,
        context_length: normalized.contextLength,
        rendered_length: normalized.renderedLength,
        error: normalized.error,
    });

    if (typeof console !== 'undefined' && console.debug) {
        console.debug('[ai-media] resolved prompt', {
            prompt_id: normalized.promptId,
            source: normalized.source,
            media_target: normalized.mediaTarget,
            task_id: normalized.taskId,
            context_length: normalized.contextLength,
            rendered_length: normalized.renderedLength,
        });
    }

    return normalized;
}
