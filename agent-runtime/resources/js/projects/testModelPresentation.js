export function formatExecutedModel(value) {
    const words = String(value || '').replace(/[_-]+/g, ' ').trim();
    return words.split(/\s+/).map((word) => {
        const lower = word.toLowerCase();
        if (lower === 'deepseek') return 'DeepSeek';
        if (lower === 'gemini') return 'Gemini';
        if (lower === 'imagen') return 'Imagen';
        if (lower === 'openai') return 'OpenAI';
        if (lower === 'openrouter') return 'OpenRouter';
        if (/^v?\d/i.test(word)) return word.toUpperCase();
        return `${word.charAt(0).toUpperCase()}${word.slice(1)}`;
    }).join(' ');
}

export function executedModelsText(models) {
    if (!Array.isArray(models) || models.length === 0) return '';
    const duplicateNames = new Set();
    const counts = new Map();
    models.forEach((item) => counts.set(String(item.model).toLowerCase(), (counts.get(String(item.model).toLowerCase()) || 0) + 1));
    counts.forEach((count, name) => { if (count > 1) duplicateNames.add(name); });
    const labels = models.map((item) => {
        const model = formatExecutedModel(item.model);
        return duplicateNames.has(String(item.model).toLowerCase()) && item.provider
            ? `${formatExecutedModel(item.provider)} · ${model}`
            : model;
    });
    return `${models.length === 1 ? 'Model' : 'Models'}: ${labels.join(' · ')}`;
}
