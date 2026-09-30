const POPUP_BLOCKED_MESSAGE = 'Trình duyệt đã chặn cửa sổ chỉnh sửa ảnh. Hãy cho phép popup cho trang này.';

export async function openMediaEditorPopup({ directUrl = '', prepareUrl, notifyDanger }) {
    const popup = window.open('about:blank', 'seo-media-image-editor');

    if (!popup) {
        await notifyDanger?.(POPUP_BLOCKED_MESSAGE);

        return null;
    }

    try {
        const prepared = directUrl ? null : await prepareUrl();
        const editorUrl = directUrl || prepared?.editor_url;
        if (!editorUrl) {
            throw new Error('Không mở được trình chỉnh sửa.');
        }
        if (popup.closed) {
            throw new Error('Cửa sổ chỉnh sửa ảnh đã bị đóng.');
        }

        popup.location.replace(editorUrl);

        return { editorUrl, prepared };
    } catch (error) {
        if (!popup.closed) {
            popup.close();
        }

        await notifyDanger?.(error?.message ?? 'Không mở được trình chỉnh sửa.');

        return null;
    }
}

export { POPUP_BLOCKED_MESSAGE };
