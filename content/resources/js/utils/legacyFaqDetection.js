const normalizeText = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();

const stripQuestionPrefix = (value) => String(value ?? '')
    .replace(/^\s*(?:q(?:uestion)?\s*\d*|hỏi|câu\s+hỏi)\s*[:.)-]\s*/iu, '')
    .replace(/^\s*❓\s*/u, '')
    .trim();

const logicalNodes = (root) => Array.from(root.querySelectorAll('h3,h4,h5,h6,p,li')).filter(
    (node) => !node.parentElement?.closest('h3,h4,h5,h6,p,li'),
);

const splitAtBreaks = (node) => {
    if (!node.querySelector('br')) {
        return [node];
    }

    const parts = [];
    let wrapper = node.cloneNode(false);
    Array.from(node.childNodes).forEach((child) => {
        if (child.nodeType === Node.ELEMENT_NODE && child.nodeName === 'BR') {
            if (String(wrapper.textContent ?? '').trim() !== '') parts.push(wrapper);
            wrapper = node.cloneNode(false);
        } else {
            wrapper.appendChild(child.cloneNode(true));
        }
    });
    if (String(wrapper.textContent ?? '').trim() !== '') parts.push(wrapper);
    return parts;
};

const isFullLineBold = (node, text) => {
    const bold = node.querySelector('strong,b');
    if (!bold) return false;
    const boldText = String(bold.textContent ?? '').replace(/\s+/g, ' ').trim();
    return boldText !== '' && boldText === text;
};

const isQuestion = (node) => {
    const text = String(node.textContent ?? '').replace(/\s+/g, ' ').trim();
    if (!text) return false;
    if (/^H[3-6]$/i.test(node.tagName)) return true;
    if (isFullLineBold(node, text)) return true;
    if (/^(?:q(?:uestion)?\s*\d*|hỏi|câu\s+hỏi)\s*[:.)-]|^❓/iu.test(text)) return true;
    return /[?？]\s*$/u.test(text);
};

export function detectFaqPairsFromSection(sectionHtml) {
    const doc = new DOMParser().parseFromString(String(sectionHtml ?? ''), 'text/html');
    doc.body.querySelector('h2')?.remove();
    const units = logicalNodes(doc.body).flatMap(splitAtBreaks);
    const rows = [];
    let current = null;

    units.forEach((node) => {
        const text = String(node.textContent ?? '').replace(/\s+/g, ' ').trim();
        if (!text) return;
        if (isQuestion(node)) {
            if (current?.answerParts.length) {
                rows.push({ question: current.question, answer: current.answerParts.join('') });
            }
            current = { question: stripQuestionPrefix(text), answerParts: [] };
            return;
        }
        if (current) current.answerParts.push(node.outerHTML);
    });

    if (current?.answerParts.length) {
        rows.push({ question: current.question, answer: current.answerParts.join('') });
    }

    return rows.filter((row) => row.question !== '' && String(row.answer).trim() !== '');
}

export function sectionHtml(section, blockById) {
    return (section?.blockIds ?? [])
        .map((id) => String(blockById.get(id)?.content ?? ''))
        .join('');
}

export function findLegacyFaqSection(sections, keywords, ignoredIds = new Set()) {
    const eligible = (Array.isArray(sections) ? sections : [])
        .filter((section) => !section.isIntro)
        .slice(-3);
    const normalizedKeywords = (Array.isArray(keywords) ? keywords : [])
        .map(normalizeText)
        .filter(Boolean);

    return eligible.find((section) => {
        if (ignoredIds.has(section.id)) return false;
        const heading = normalizeText(section.title);
        return normalizedKeywords.some((keyword) => heading === keyword || heading.includes(keyword));
    }) ?? null;
}
