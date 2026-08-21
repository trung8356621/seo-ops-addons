import { answerHtmlForEditor } from '../../resources/js/utils/faqAnswerHtml.js';
import {
    faqAnswerPlainText,
    faqRowsNeedPersistFlush,
    isFaqDraftPlaceholder,
    isFaqUnpersistedLocal,
    mergeFaqRowsPreservingDrafts,
    mergeGeneratedFaqsWithExisting,
    normalizeFaqQuestionKey,
} from '../../resources/js/utils/faqDraftPlaceholders.js';

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

// question + empty answer stays during edit
{
    const local = [
        {
            id: 1,
            client_key: 'faq-id-1',
            question: 'Existing?',
            answer: '<p>Yes</p>',
        },
        {
            id: null,
            client_key: 'faq-draft-manual',
            question: 'Số lượng tối thiểu để đặt may túi vải tote đại học in logo theo yêu cầu là bao nhiêu?',
            answer: '<p></p>',
        },
    ];
    const server = [
        {
            id: 1,
            question: 'Existing?',
            answer: '<p>Yes</p>',
        },
    ];
    const { rows, needsFlush } = mergeFaqRowsPreservingDrafts(server, local);
    assertEqual(rows.length, 2, 'keep question-only manual row after ACK');
    assertEqual(rows[1].client_key, 'faq-draft-manual', 'stable client_key');
    assertEqual(
        rows[1].question,
        'Số lượng tối thiểu để đặt may túi vải tote đại học in logo theo yêu cầu là bao nhiêu?',
        'question preserved',
    );
    assert(isFaqUnpersistedLocal(rows[1]), 'question-only is unpersisted local');
    assertEqual(needsFlush, false, 'incomplete draft does not force flush');
}

// generated + manual coexist
{
    const existing = [
        {
            id: null,
            client_key: 'manual-1',
            question: 'Manual question?',
            answer: '<p>Manual answer</p>',
        },
    ];
    const generated = [
        { question: 'Manual question?', answer: '<p>AI dup</p>' },
        { question: 'Generated question?', answer: '<p>AI answer</p>' },
    ];
    const merged = mergeGeneratedFaqsWithExisting(existing, generated);
    assertEqual(merged.length, 2, 'dedupe generated against manual');
    assertEqual(merged[0].question, 'Manual question?', 'manual first');
    assertEqual(merged[1].question, 'Generated question?', 'generated appended');
}

// delete middle does not scramble identities via merge
{
    const local = [
        { id: 10, client_key: 'a', question: 'Q1?', answer: '<p>A1</p>' },
        { id: 30, client_key: 'c', question: 'Q3?', answer: '<p>A3</p>' },
    ];
    const server = [
        { id: 10, question: 'Q1?', answer: '<p>A1</p>' },
        { id: 30, question: 'Q3?', answer: '<p>A3</p>' },
    ];
    const { rows } = mergeFaqRowsPreservingDrafts(server, local);
    assertEqual(rows[0].client_key, 'a', 'key #1 stable after delete #2');
    assertEqual(rows[1].client_key, 'c', 'key #3 stable after delete #2');
}

// complete row typed during in-flight save is kept and needs flush
{
    const server = [{ id: 1, question: 'Old?', answer: '<p>Old</p>' }];
    const localNow = [
        { id: 1, client_key: 'faq-id-1', question: 'Old?', answer: '<p>Old</p>' },
        {
            id: null,
            client_key: 'faq-draft-new',
            question: 'New manual?',
            answer: '<p>Filled during flight</p>',
        },
    ];
    const { rows, needsFlush } = mergeFaqRowsPreservingDrafts(server, localNow);
    assertEqual(rows.length, 2, 'complete raced row kept');
    assert(needsFlush, 'needs second flush for raced complete row');
    assert(faqRowsNeedPersistFlush(rows), 'complete without id needs persist');
}

// autosave ACK must not duplicate by question
{
    const server = [
        { id: 5, question: 'Same Q?', answer: '<p>Saved</p>' },
    ];
    const local = [
        { id: null, client_key: 'draft', question: 'Same Q?', answer: '<p>Saved</p>' },
        { id: null, client_key: 'empty', question: '', answer: '<p></p>' },
    ];
    const { rows } = mergeFaqRowsPreservingDrafts(server, local);
    const same = rows.filter((row) => normalizeFaqQuestionKey(row.question) === 'same q?');
    assertEqual(same.length, 1, 'no duplicate row after autosave ACK');
    assertEqual(rows.length, 2, 'empty draft still preserved once');
    assert(isFaqDraftPlaceholder(rows[1]), 'empty draft placeholder');
}

assertEqual(faqAnswerPlainText('<p></p>'), '', 'empty p is blank');
assertEqual(answerHtmlForEditor(''), '<p></p>', 'editor empty html');

console.log('faqDraftPlaceholders.selftest: ok');
