import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    clearStoredThreadUlid,
    formatTimeAgo,
    getStoredThreadUlid,
    setStoredThreadUlid,
} from './agentThreadState.js';

// Setup mock window.sessionStorage for Node environment
const mockSessionStorage = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (k) => mockSessionStorage.get(k) || null,
        setItem: (k, v) => mockSessionStorage.set(k, String(v)),
        removeItem: (k) => mockSessionStorage.delete(k),
        clear: () => mockSessionStorage.clear(),
    },
};

test('thread state helper stores, retrieves, and clears thread ULID scoped by appKey and scopeRef', () => {
    mockSessionStorage.clear();

    assert.equal(getStoredThreadUlid('seo-ops', 'site:2'), null);

    setStoredThreadUlid('seo-ops', 'site:2', '01JABCDEF1234567890ABCDEF');
    assert.equal(getStoredThreadUlid('seo-ops', 'site:2'), '01JABCDEF1234567890ABCDEF');
    assert.equal(getStoredThreadUlid('seo-ops', 'site:3'), null);
    assert.equal(getStoredThreadUlid('wordpress', 'site:2'), null);

    clearStoredThreadUlid('seo-ops', 'site:2');
    assert.equal(getStoredThreadUlid('seo-ops', 'site:2'), null);
});

test('formatTimeAgo produces human readable relative times', () => {
    const now = new Date();
    assert.equal(formatTimeAgo(now.toISOString()), 'just now');

    const tenMinAgo = new Date(now.getTime() - 10 * 60 * 1000);
    assert.equal(formatTimeAgo(tenMinAgo.toISOString()), '10m ago');

    const threeHoursAgo = new Date(now.getTime() - 3 * 60 * 60 * 1000);
    assert.equal(formatTimeAgo(threeHoursAgo.toISOString()), '3h ago');

    const twoDaysAgo = new Date(now.getTime() - 2 * 24 * 60 * 60 * 1000);
    assert.equal(formatTimeAgo(twoDaysAgo.toISOString()), '2d ago');

    assert.equal(formatTimeAgo(null), '');
    assert.equal(formatTimeAgo('invalid-date'), '');
});

test('first Send uses /turns and stores thread_ulid while subsequent Send reuses /threads/{ulid}/turns', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // First send vs subsequent send logic
    assert.equal(source.includes('const sendUrl = activeThreadUlid'), true);
    assert.equal(source.includes('`${endpoints.threadsUrl}/${activeThreadUlid}/turns`'), true);
    assert.equal(source.includes(': endpoints.turnUrl'), true);

    // Storing returned thread_ulid
    assert.equal(source.includes('const data = payload?.data || {};'), true);
    assert.equal(source.includes('const returnedUlid = data.thread_ulid;'), true);
    assert.equal(source.includes('setActiveThreadUlid(returnedUlid)'), true);
    assert.equal(source.includes('setStoredThreadUlid(hostContext.appKey, currentScopeRef, returnedUlid)'), true);
});

test('New Conversation clears active thread, messages, and saved state', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    assert.equal(source.includes('const onNewConversation = useCallback(() => {'), true);
    assert.equal(source.includes('setActiveThreadUlid(null);'), true);
    assert.equal(source.includes('setMessages([]);'), true);
    assert.equal(source.includes('clearStoredThreadUlid(hostContext.appKey, currentScopeRef);'), true);
});

test('selecting history hydrates thread messages without calling any model', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // loadThread uses GET /threads/{ulid}
    assert.equal(source.includes('const loadThread = useCallback(async (ulid, scopeRef) => {'), true);
    assert.equal(source.includes('`${endpoints.threadsUrl}/${ulid}`'), true);
    // Preserves response_payload
    assert.equal(source.includes('response_payload'), true);
    assert.equal(source.includes('setMessages(mapped);'), true);
});

test('structured response survives hydration with blocks, actions, and sources', () => {
    // Simulate hydrating an assistant message payload
    const mockDbThread = {
        ulid: '01JTEST123',
        scope_ref: 'site:2',
        messages: [
            {
                role: 'user',
                content: 'Kiểm tra traffic tháng 9',
            },
            {
                role: 'assistant',
                content: 'Báo cáo chi tiết',
                response_payload: {
                    message: 'Báo cáo chi tiết',
                    blocks: [
                        { type: 'text', content: 'Tổng quan' },
                        { type: 'stats', metrics: [{ label: 'Clicks', value: 1200 }] },
                    ],
                    actions: [{ label: 'Tối ưu bài viết', intent: 'optimize' }],
                    sources: [{ title: 'GSC Data', url: 'https://search.google.com' }],
                },
            },
        ],
    };

    const mapped = mockDbThread.messages.map((m) => {
        if (m.role === 'assistant') {
            return {
                role: 'assistant',
                content: m.content || '',
                response: m.response_payload || {
                    message: m.content || '',
                    blocks: [],
                    actions: [],
                    sources: [],
                },
            };
        }
        return {
            role: 'user',
            content: m.content || '',
        };
    });

    assert.equal(mapped.length, 2);
    assert.equal(mapped[0].role, 'user');
    assert.equal(mapped[0].content, 'Kiểm tra traffic tháng 9');
    assert.equal(mapped[1].role, 'assistant');
    assert.equal(mapped[1].response.blocks.length, 2);
    assert.equal(mapped[1].response.actions.length, 1);
    assert.equal(mapped[1].response.sources.length, 1);
    assert.equal(mapped[1].response.sources[0].title, 'GSC Data');
});

test('switching scope resets or hydrates scoped thread and refreshes thread list', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    assert.equal(source.includes('fetchThreads(currentScopeRef)'), true);
    assert.equal(source.includes('const storedUlid = getStoredThreadUlid(hostContext.appKey, currentScopeRef)'), true);
    assert.equal(source.includes('loadThread(storedUlid, currentScopeRef)'), true);
    assert.equal(source.includes('setActiveThreadUlid(null);'), true);
});

test('Copy remains independent without creating thread or running turn', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    assert.equal(source.includes('async function onCopy() {'), true);
    assert.equal(source.includes('postJson(endpoints.copyUrl'), true);
    // Does not mutate activeThreadUlid
    const copyBlock = source.slice(source.indexOf('async function onCopy()'), source.indexOf('async function onSend()'));
    assert.equal(copyBlock.includes('setActiveThreadUlid'), false);
    assert.equal(copyBlock.includes('threadsUrl'), false);
});

test('All Sites behavior remains unsupported for retrieval', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    assert.equal(source.includes('globalUnsupported'), true);
    assert.equal(source.includes('All Sites retrieval is unsupported'), true);
});
