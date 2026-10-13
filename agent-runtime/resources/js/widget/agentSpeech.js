export function computeSpeechDraft(baseText, results) {
    const base = typeof baseText === 'string' ? baseText.trim() : '';
    if (!results || results.length === 0) {
        return base;
    }
    const parts = [];
    for (let i = 0; i < results.length; i++) {
        const item = results[i];
        const text = String(item?.[0]?.transcript ?? item?.transcript ?? '').trim();
        if (text) {
            parts.push(text);
        }
    }
    const spoken = parts.join(' ').trim();
    if (!spoken) {
        return base;
    }
    return base ? `${base} ${spoken}` : spoken;
}
