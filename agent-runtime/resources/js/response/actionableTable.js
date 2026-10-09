const ARTICLE_META_KEY = 'article_ref';

const SCORE_GOOD = 70;
const SCORE_FAIR = 50;

export function displayColumns(columns) {
    const list = Array.isArray(columns) ? columns.filter((column) => column && column.key) : [];
    const hasTitle = list.some((column) => column.key === 'title');
    if (!hasTitle) {
        return list;
    }
    return list.filter((column) => column.key !== ARTICLE_META_KEY);
}

export function columnKind(key) {
    if (key === 'n') return 'index';
    if (key === 'title') return 'article';
    if (key === 'focus_keyword' || key === 'keyword') return 'keyword';
    if (key === 'seo_score') return 'score';
    return 'extra';
}

export function rowReasons(row) {
    const raw = row?.item?.reasons;
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw.map((reason) => String(reason ?? '').trim()).filter(Boolean);
}

export function issueCountLabel(count) {
    const total = Number(count) || 0;
    if (total <= 0) {
        return '';
    }
    return total === 1 ? '1 vấn đề SEO' : `${total} vấn đề SEO`;
}

export function articleRef(row) {
    const value = row?.article_ref ?? row?.item?.article_ref ?? '';
    return String(value ?? '').trim();
}

export function formatSeoScore(value) {
    if (value === null || value === undefined || value === '') {
        return { text: '', tone: 'is-unknown' };
    }
    if (typeof value === 'boolean' || (typeof value === 'object')) {
        return { text: '', tone: 'is-unknown' };
    }
    const numeric = typeof value === 'number' ? value : Number(String(value).trim());
    if (!Number.isFinite(numeric)) {
        return { text: '', tone: 'is-unknown' };
    }
    let tone = 'is-poor';
    if (numeric >= SCORE_GOOD) tone = 'is-good';
    else if (numeric >= SCORE_FAIR) tone = 'is-fair';
    return { text: String(numeric), tone };
}

export function statusTone(status) {
    if (status === 'added') return 'is-added';
    if (status === 'already_in_draft') return 'is-exists';
    return 'is-failed';
}
