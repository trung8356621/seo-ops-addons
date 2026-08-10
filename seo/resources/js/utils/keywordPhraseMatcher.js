function stripApostrophes(text) {
    return String(text ?? '').replace(
        /[\u0027\u2018\u2019\u201B\u2032\u0060\u00B4\u02BC\uFF07]+/gu,
        '',
    );
}

export function normalizePhrase(text) {
    let value = String(text ?? '').trim();
    if (value === '') {
        return '';
    }

    const textarea = typeof document !== 'undefined' ? document.createElement('textarea') : null;
    if (textarea) {
        textarea.innerHTML = value;
        value = textarea.value;
    }

    value = stripApostrophes(value.toLowerCase());
    value = value.replace(/[^\p{L}\p{N}\s]+/gu, ' ');
    value = value.replace(/\s+/gu, ' ').trim();

    return value;
}

export function containsKeywordPhrase(haystack, needle) {
    const normalizedHaystack = normalizePhrase(haystack);
    const normalizedNeedle = normalizePhrase(needle);

    if (normalizedNeedle === '' || normalizedHaystack === '') {
        return false;
    }

    return normalizedHaystack.includes(normalizedNeedle);
}

export function countKeywordOccurrences(haystack, needle) {
    const normalizedHaystack = normalizePhrase(haystack);
    const normalizedNeedle = normalizePhrase(needle);

    if (normalizedNeedle === '' || normalizedHaystack === '') {
        return 0;
    }

    let count = 0;
    let offset = 0;

    while (true) {
        const position = normalizedHaystack.indexOf(normalizedNeedle, offset);
        if (position === -1) {
            break;
        }

        count += 1;
        offset = position + normalizedNeedle.length;
    }

    return count;
}

export function countPhraseWords(text) {
    const normalized = normalizePhrase(text);
    if (normalized === '') {
        return 0;
    }

    return normalized.split(/\s+/u).filter(Boolean).length;
}
