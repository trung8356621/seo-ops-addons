import { normalizeArticleSlug } from '@content-addon/utils/articleSlugUtils.js';

function normalizeText(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'd')
        .toLowerCase()
        .trim();
}

function containsKeyword(text, keyword) {
    const haystack = normalizeText(text);
    const needle = normalizeText(keyword);

    if (haystack === '' || needle === '') {
        return false;
    }

    return haystack.includes(needle);
}

function keywordInFirstThreeWords(title, keyword) {
    const words = String(title ?? '')
        .trim()
        .split(/\s+/u)
        .filter(Boolean)
        .slice(0, 3);

    if (words.length === 0) {
        return false;
    }

    return containsKeyword(words.join(' '), keyword);
}

function slugContainsKeyword(slug, keyword) {
    const keywordSlug = normalizeArticleSlug(keyword);
    const articleSlug = normalizeArticleSlug(slug);

    if (keywordSlug === '' || articleSlug === '') {
        return false;
    }

    return articleSlug.includes(keywordSlug);
}

function clampScore(score) {
    return Math.max(0, Math.min(100, Math.round(score)));
}

export function scoreTitleLine(title, focusKeyword = '') {
    const text = String(title ?? '').trim();
    const keyword = String(focusKeyword ?? '').trim();

    if (text === '') {
        return 15;
    }

    let score = 100;

    if (text.length > 60) {
        score -= 20;
    }

    if (keyword !== '') {
        if (!containsKeyword(text, keyword)) {
            score -= 35;
        } else if (!keywordInFirstThreeWords(text, keyword)) {
            score -= 15;
        }
    }

    return clampScore(score);
}

export function scoreDescriptionLine(description, focusKeyword = '') {
    const text = String(description ?? '').trim();
    const keyword = String(focusKeyword ?? '').trim();
    const length = text.length;

    if (length === 0) {
        return 20;
    }

    let score = 100;

    if (length > 160) {
        score -= 40;
    } else if (length < 120) {
        score -= 25;
    }

    if (keyword !== '' && !containsKeyword(text, keyword)) {
        score -= 30;
    }

    return clampScore(score);
}

export const SLUG_LENGTH_WARN = 80;
export const SLUG_LENGTH_MAX = 85;

export function slugLengthMeterClass(length) {
    const value = Number(length) || 0;

    if (value > SLUG_LENGTH_MAX) {
        return ' is-over';
    }

    if (value >= SLUG_LENGTH_WARN) {
        return ' is-warn';
    }

    return ' is-good';
}

export function slugLengthMeterPercent(length) {
    const value = Number(length) || 0;

    return Math.min(100, Math.round((value / SLUG_LENGTH_MAX) * 100));
}

export function scoreSlugLine(slug, focusKeyword = '') {
    const text = String(slug ?? '').trim();
    const keyword = String(focusKeyword ?? '').trim();
    const length = text.length;

    if (length === 0) {
        return 20;
    }

    let score = 100;

    if (length > SLUG_LENGTH_MAX) {
        score -= 45;
    } else if (length >= SLUG_LENGTH_WARN) {
        score -= 25;
    }

    if (keyword !== '' && !slugContainsKeyword(text, keyword)) {
        score -= 25;
    }

    return clampScore(score);
}

export function computeGoogleSerpLineScores({ title = '', description = '', slug = '', focusKeyword = '' } = {}) {
    return {
        title: scoreTitleLine(title, focusKeyword),
        description: scoreDescriptionLine(description, focusKeyword),
        slug: scoreSlugLine(slug, focusKeyword),
    };
}
