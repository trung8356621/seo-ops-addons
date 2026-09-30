function normalizeTextSegment(text) {
    return text
        .replace(/&#x20;/gi, ' ')
        .replace(/^(\s*)\\(#{1,6})(?=\s)/gm, '$1$2')
        .replace(/^(\s*)\\([*+-])(?=\s)/gm, '$1$2')
        .replace(/^(\s*\d+)\\\.(?=\s)/gm, '$1.')
        .replace(/\\\*\\\*([^\n]+?)\\\*\\\*/g, '**$1**')
        .replace(/https?\\:\/\//g, (m) => m.replace('\\', ''))
        .replace(/\\\[([^\n\]]+)\\\]\s*(?=[\\(])/g, '[$1]')
        .replace(/\]\s*\\\(/g, '](')
        .replace(/(https?:\/\/[^\s)]+)\\\)/g, '$1)');
}

/** Normalize only known model presentation escapes, preserving inline/fenced code verbatim. */
export function normalizeModelMarkdown(value) {
    const text = String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    return text.split(/(```[\s\S]*?```|`[^`\n]*`)/g)
        .map((part, index) => index % 2 === 1 ? part : normalizeTextSegment(part))
        .join('');
}

function escapeHtml(value) {
    return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

export function modelMarkdownToHtml(text) {
    const normalized = normalizeModelMarkdown(text);
    const code = [];
    const escaped = escapeHtml(normalized).replace(/```(?:\w+)?\n?([\s\S]*?)```/g, (_, body) => {
        const token = `@@AGENT_CODE_${code.length}@@`;
        code.push(`<pre><code>${body}</code></pre>`);
        return token;
    });
    const inlineCodes = [];
    const withoutInlineCodes = escaped.replace(/`([^`\n]+)`/g, (_, body) => {
        const token = `@@AGENT_INLINE_${inlineCodes.length}@@`;
        inlineCodes.push(`<code>${body}</code>`);
        return token;
    });

    const inline = withoutInlineCodes
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/__(.+?)__/g, '<strong>$1</strong>')
        .replace(/(?<=^|[\s(])\*(?!\s)([^*\n]+?)(?<!\s)\*(?=[.,!?;:\s)]|$)/g, '<em>$1</em>')
        .replace(/(?<=^|[\s(])_(?!\s)([^_\n]+?)(?<!\s)_(?=[.,!?;:\s)]|$)/g, '<em>$1</em>');

    const lines = inline.split('\n');
    const out = [];

    let inLevel0List = null;
    let inLevel0Item = false;
    let inLevel1List = null;

    const closeLevel1 = () => {
        if (inLevel1List) {
            out.push(`</${inLevel1List}>`);
            inLevel1List = null;
        }
    };

    const closeLevel0Item = () => {
        closeLevel1();
        if (inLevel0Item) {
            out.push('</li>');
            inLevel0Item = false;
        }
    };

    const closeAllLists = () => {
        closeLevel0Item();
        if (inLevel0List) {
            out.push(`</${inLevel0List}>`);
            inLevel0List = null;
        }
    };

    for (const line of lines) {
        const heading = line.match(/^(#{1,6})\s+(.+)$/);
        const listItem = line.match(/^(\s*)(?:([-*])|(\d+)\.)\s+(.+)$/);
        if (heading) {
            closeAllLists();
            out.push(`<h${heading[1].length}>${heading[2]}</h${heading[1].length}>`);
        } else if (listItem) {
            const indent = listItem[1].length;
            const isLevel1 = indent >= 2 && inLevel0Item;
            const nextListType = listItem[2] ? 'ul' : 'ol';
            const content = listItem[4];

            if (isLevel1) {
                if (inLevel1List !== nextListType) {
                    closeLevel1();
                    out.push(`<${nextListType}>`);
                    inLevel1List = nextListType;
                }
                out.push(`<li>${content}</li>`);
            } else {
                closeLevel0Item();
                if (inLevel0List !== nextListType) {
                    if (inLevel0List) {
                        out.push(`</${inLevel0List}>`);
                    }
                    out.push(`<${nextListType}>`);
                    inLevel0List = nextListType;
                }
                out.push(`<li>${content}`);
                inLevel0Item = true;
            }
        } else {
            closeAllLists();
            if (line !== '') out.push(`<p>${line}</p>`);
        }
    }
    closeAllLists();

    return out.join('')
        .replace(/@@AGENT_INLINE_(\d+)@@/g, (_, index) => inlineCodes[Number(index)] || '')
        .replace(/@@AGENT_CODE_(\d+)@@/g, (_, index) => code[Number(index)] || '');
}

export function resolveWarningClass(block, sources = []) {
    const text = String(block?.text || '');
    const structuredReasons = new Set((sources || [])
        .filter((source) => source?.status === 'unavailable' && ['no_gsc_property', 'no_synced_data'].includes(source?.reason))
        .map((source) => source.reason));

    const isAvailability = Boolean(
        (block?.reason && ['no_gsc_property', 'no_synced_data'].includes(block.reason))
        || (structuredReasons.size > 0 && (
            [...structuredReasons].some((reason) => text.includes(reason))
            || /\bGSC\b|Google Search Console/i.test(text)
        ))
        || /no_gsc_property|no_synced_data/.test(text)
    );

    return isAvailability ? 'agent-availability-note' : 'agent-warning';
}

