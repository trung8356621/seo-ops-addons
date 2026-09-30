import assert from 'node:assert/strict';
import test from 'node:test';
import { modelMarkdownToHtml, normalizeModelMarkdown, resolveWarningClass } from './markdownPresentation.js';

test('over-escaped headings render as headings', () => {
    const input = String.raw`\### 1. Overview of Traffic
\## Next Steps
\#### Sub details`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<h3>1. Overview of Traffic</h3>'), true);
    assert.equal(html.includes('<h2>Next Steps</h2>'), true);
    assert.equal(html.includes('<h4>Sub details</h4>'), true);
});

test('over-escaped bullets render as bullets', () => {
    const input = String.raw`\- First item
\- Second item
\* Third item`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<ul><li>First item</li><li>Second item</li><li>Third item</li></ul>'), true);
});

test('escaped bold renders correctly', () => {
    const input = String.raw`This is \*\*bold text\*\* in answer`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<strong>bold text</strong>'), true);
    assert.equal(html.includes(String.raw`\*\*`), false);
});

test('escaped ordered-list markers render correctly', () => {
    const input = String.raw`1\. First step
2\. Second step`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<ol><li>First step</li><li>Second step</li></ol>'), true);
});

test('&#x20; artifacts are removed from display', () => {
    const input = String.raw`&#x20;  \- child bullet item`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('&#x20;'), false);
    assert.equal(html.includes('<ul><li>child bullet item</li></ul>'), true);
});

test('https\\:// and over-escaped Markdown links render correctly', () => {
    const input = String.raw`[https\://example.com/search-console]\(https\://example.com/search-console\)
Visit [Google Search Console]\(https\://search.google.com/search-console\) for details.`;
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('href="https://example.com/search-console"'), true);
    assert.equal(html.includes('>https://example.com/search-console</a>'), true);
    assert.equal(html.includes('href="https://search.google.com/search-console"'), true);
    assert.equal(html.includes('>Google Search Console</a>'), true);
    assert.equal(html.includes(String.raw`\://`), false);
    assert.equal(html.includes(String.raw`\(`), false);
    assert.equal(html.includes(String.raw`\)`), false);
});

test('legitimate code and backslashes are not globally stripped', () => {
    const input = [
        'Path C:\\work\\site and regex \\d+ in regular text',
        '',
        '`C:\\work\\inline and \\### literal`',
        '',
        '```',
        '\\### fenced code block',
        'const regex = /\\d+/;',
        'C:\\work\\fenced',
        '```',
    ].join('\n');
    const normalized = normalizeModelMarkdown(input);
    assert.equal(normalized.includes('C:\\work\\site'), true);
    assert.equal(normalized.includes('regex \\d+'), true);
    assert.equal(normalized.includes('\\### literal'), true);
    assert.equal(normalized.includes('\\### fenced code block'), true);
    assert.equal(normalized.includes('const regex = /\\d+/;'), true);
    assert.equal(normalized.includes('C:\\work\\fenced'), true);

    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<code>C:\\work\\inline and \\### literal</code>'), true);
    assert.equal(html.includes('<pre><code>\\### fenced code block'), true);
});

test('GSC no_gsc_property availability notice is muted, not warning-card styled', () => {
    // Textual warning containing no_gsc_property
    const classFromText = resolveWarningClass({
        type: 'warning',
        text: 'Dữ liệu Google Search Console tháng 2026-09 không khả dụng cho site này (lý do: no_gsc_property).',
    });
    assert.equal(classFromText, 'agent-availability-note');

    // Structured source with no_gsc_property
    const classFromSource = resolveWarningClass(
        { type: 'warning', text: 'Dữ liệu Google Search Console tháng 2026-09 chưa có.' },
        [{ name: 'Google Search Console', status: 'unavailable', reason: 'no_gsc_property' }]
    );
    assert.equal(classFromSource, 'agent-availability-note');
});

test('no_synced_data receives the same availability treatment', () => {
    // Textual warning containing no_synced_data
    const classFromText = resolveWarningClass({
        type: 'warning',
        text: 'Dữ liệu phân tích chưa đồng bộ (lý do: no_synced_data).',
    });
    assert.equal(classFromText, 'agent-availability-note');

    // Structured source with no_synced_data
    const classFromSource = resolveWarningClass(
        { type: 'warning', text: 'Dữ liệu Google Search Console hiện tại chưa có sẵn.' },
        [{ name: 'Google Search Console', status: 'unavailable', reason: 'no_synced_data' }]
    );
    assert.equal(classFromSource, 'agent-availability-note');
});

test('unrelated real warning blocks retain warning styling', () => {
    const class1 = resolveWarningClass({
        type: 'warning',
        text: 'Rate limit exceeded: 429 Too Many Requests to API endpoint.',
    });
    assert.equal(class1, 'agent-warning');

    const class2 = resolveWarningClass({
        type: 'warning',
        text: 'Authentication failed for upstream service.',
    }, [{ name: 'GSC', status: 'available' }]);
    assert.equal(class2, 'agent-warning');
});

test('1. *(note)* renders as <em> inside paragraph', () => {
    const input = '*(Lưu ý: đây là đề xuất mới)*';
    const html = modelMarkdownToHtml(input);
    assert.equal(html, '<p><em>(Lưu ý: đây là đề xuất mới)</em></p>');
});

test('2. *Ý tưởng mới*: inside a list item renders emphasis', () => {
    const input = '- *Ý tưởng mới*: Hướng dẫn đặt may B2B';
    const html = modelMarkdownToHtml(input);
    assert.equal(html, '<ul><li><em>Ý tưởng mới</em>: Hướng dẫn đặt may B2B</li></ul>');
});

test('3. _italic_ renders as <em>', () => {
    const input = 'This is _italic_ text and (_in parens_)';
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<em>italic</em>'), true);
    assert.equal(html.includes('<em>in parens</em>'), true);
});

test('4. bold still works with double stars and underscores', () => {
    const input = 'This is **bold text** and __another bold__';
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<strong>bold text</strong>'), true);
    assert.equal(html.includes('<strong>another bold</strong>'), true);
});

test('5. italic and bold do not corrupt each other', () => {
    const input1 = '**bold and *italic* inside**';
    const html1 = modelMarkdownToHtml(input1);
    assert.equal(html1, '<p><strong>bold and <em>italic</em> inside</strong></p>');

    const input2 = '*italic and **bold** inside*';
    const html2 = modelMarkdownToHtml(input2);
    assert.equal(html2, '<p><em>italic and <strong>bold</strong> inside</em></p>');

    const input3 = '**bold** and *italic* and _italic_ and __bold__';
    const html3 = modelMarkdownToHtml(input3);
    assert.equal(html3, '<p><strong>bold</strong> and <em>italic</em> and <em>italic</em> and <strong>bold</strong></p>');
});

test('6. list marker * item remains a list, not emphasis', () => {
    const input = '* First item\n* Second item';
    const html = modelMarkdownToHtml(input);
    assert.equal(html, '<ul><li>First item</li><li>Second item</li></ul>');
    assert.equal(html.includes('<em>'), false);
});

test('7. one nested list level preserves hierarchy and indentation', () => {
    const input = [
        '- Nhóm A',
        '  - Ý tưởng 1',
        '  - Ý tưởng 2',
        '- Nhóm B',
        '  - Ý tưởng 3',
    ].join('\n');
    const html = modelMarkdownToHtml(input);
    assert.equal(html, '<ul><li>Nhóm A<ul><li>Ý tưởng 1</li><li>Ý tưởng 2</li></ul></li><li>Nhóm B<ul><li>Ý tưởng 3</li></ul></li></ul>');
});

test('8. inline code containing *text* stays literal', () => {
    const input = 'Use `*do not italicize*` and `_literal_` in code';
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<code>*do not italicize*</code>'), true);
    assert.equal(html.includes('<code>_literal_</code>'), true);
    assert.equal(html.includes('<em>'), false);
});

test('9. fenced code stays literal', () => {
    const input = [
        '```',
        'function test() {',
        '  // *comment* and _underscore_',
        '  return 1 * 2;',
        '}',
        '```',
    ].join('\n');
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('<pre><code>function test() {\n  // *comment* and _underscore_\n  return 1 * 2;\n}\n</code></pre>'), true);
    assert.equal(html.includes('<em>'), false);
});

test('10. existing escaping normalization tests remain green and no_gsc_property is not italicized', () => {
    const input = 'Identifier no_gsc_property and some_function_name should not be italicized';
    const html = modelMarkdownToHtml(input);
    assert.equal(html.includes('no_gsc_property'), true);
    assert.equal(html.includes('some_function_name'), true);
    assert.equal(html.includes('<em>'), false);
});

