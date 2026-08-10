/**
 * Chuẩn hóa câu trả lời FAQ (plain text / HTML) cho TipTap.
 */
export function answerHtmlForEditor(answer) {
    const raw = (answer ?? '').trim();
    if (raw === '') {
        return '<p></p>';
    }

    if (/<[a-z][\s\S]*>/i.test(raw)) {
        return raw;
    }

    const lines = raw.split(/\r\n|\r|\n/).map((line) => line.trim()).filter(Boolean);
    if (lines.length === 0) {
        return '<p></p>';
    }

    return lines.map((line) => `<p>${escapeHtml(line)}</p>`).join('');
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
