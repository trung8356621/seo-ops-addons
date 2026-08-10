import React, { useEffect, useState } from 'react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { loadOutline, saveOutline } from '../utils/articleEditorStorage';

export default function OutlineMarkdownPanel({ articleId, initialOutline = '', onRewriteOutline = null }) {
    const [markdown, setMarkdown] = useState('');
    const [loaded, setLoaded] = useState(false);
    const [isRewriting, setIsRewriting] = useState(false);

    const { debounced: debouncedSave } = useDebouncedCallback((value) => {
        if (articleId) {
            saveOutline(articleId, value);
        }
    }, 800);

    useEffect(() => {
        if (!articleId) {
            setMarkdown(initialOutline || '');
            setLoaded(true);
            return;
        }

        const draft = loadOutline(articleId);
        if (draft !== null && (draft.trim() !== '' || String(initialOutline || '').trim() === '')) {
            setMarkdown(draft);
        } else {
            setMarkdown(initialOutline || '');
            if (String(initialOutline || '').trim() !== '') {
                saveOutline(articleId, initialOutline);
            }
        }
        setLoaded(true);
    }, [articleId, initialOutline]);

    useEffect(() => {
        const handleOutlineRewritten = (event) => {
            const detail = event?.detail && typeof event.detail === 'object' ? event.detail : {};
            const nextOutline = String(detail.outline || '').trim();
            const success = Boolean(detail.success) && nextOutline !== '';

            setIsRewriting(false);

            if (!success) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            status: 'warning',
                            title: 'Không tạo được outline',
                            body: String(detail.message || 'Workflow không trả về outline.'),
                        },
                    }),
                );
                return;
            }

            setMarkdown(nextOutline);
            if (articleId) {
                saveOutline(articleId, nextOutline);
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        status: 'success',
                        title: 'Đã tạo outline',
                        body: String(detail.message || 'Outline đã được cập nhật.'),
                    },
                }),
            );
        };

        window.addEventListener('seo-outline-rewritten', handleOutlineRewritten);

        return () => {
            window.removeEventListener('seo-outline-rewritten', handleOutlineRewritten);
        };
    }, [articleId]);

    const handleChange = (e) => {
        const value = e.target.value;
        setMarkdown(value);
        debouncedSave(value);
    };

    const handleRewrite = (mode) => {
        if (typeof onRewriteOutline !== 'function' || isRewriting) {
            return;
        }
        setIsRewriting(true);
        onRewriteOutline(mode);
    };

    if (!loaded) {
        return (
            <p className="text-gray-400 text-center py-10 italic text-sm">Đang tải dàn ý…</p>
        );
    }

    const hasOutline = Boolean(markdown.trim());

    return (
        <div className="seo-outline-panel">
            <p className="seo-outline-panel-hint">
                {hasOutline
                    ? 'Markdown outline (stored locally in browser).'
                    : 'No outline yet — you can type markdown below.'}
            </p>
            {!hasOutline ? (
                <div className="seo-outline-actions">
                    <button
                        type="button"
                        className="seo-outline-action-btn"
                        disabled={isRewriting}
                        onClick={() => handleRewrite('title')}
                    >
                        {isRewriting ? 'Đang xử lý…' : 'Viết lại theo tiêu đề'}
                    </button>
                    <button
                        type="button"
                        className="seo-outline-action-btn"
                        disabled={isRewriting}
                        onClick={() => handleRewrite('content')}
                    >
                        {isRewriting ? 'Đang xử lý…' : 'Viết lại theo nội dung'}
                    </button>
                </div>
            ) : null}
            <textarea
                className="seo-outline-editor"
                value={markdown}
                onChange={handleChange}
                placeholder={'# Title\n\n## Section 1\n- Main point...'}
                spellCheck={false}
            />
            {hasOutline ? (
                <details className="seo-outline-preview-wrap">
                    <summary className="seo-outline-preview-summary">Preview</summary>
                    <pre className="seo-outline-preview">{markdown}</pre>
                </details>
            ) : null}
        </div>
    );
}
