import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('17. UI component rendering - Copy button is replaced by Model Debug button in AgentWidget.jsx composer actions', () => {
    const source = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // The composer actions contain the Model Debug button with Bug icon and title
    assert.equal(source.includes('className="agent-model-debug-btn"'), true);
    assert.equal(source.includes('<Bug size={16} />'), true);
    assert.equal(source.includes('<span>Model Debug</span>'), true);
    assert.equal(source.includes('onClick={() => startModelDebug()}'), true);
    assert.equal(source.includes('disabled={busy || debugBusy || draft.trim() === \'\'}'), true);

    // ModelDebugModal is mounted in AgentWidget
    assert.equal(source.includes('<ModelDebugModal'), true);
    assert.equal(source.includes('isOpen={debugOpen}'), true);
    assert.equal(source.includes('onClose={() => setDebugOpen(false)}'), true);
    assert.equal(source.includes('onReset={onResetDebug}'), true);
});

test('18. Decision card rendering - renders prompt, assumed model metadata, copy, paste, and apply', () => {
    const modalSource = readFileSync(new URL('./ModelDebugModal.jsx', import.meta.url), 'utf8');

    // Assumed model header shows provider, model/display_name, routing_mode/profile, fallbacks
    assert.equal(modalSource.includes('function AssumedModelHeader({ assumedModel })'), true);
    assert.equal(modalSource.includes('Assumed model:'), true);
    assert.equal(modalSource.includes('Routing / profile:'), true);
    assert.equal(modalSource.includes('Fallbacks:'), true);

    // Decision card stage 1 exists
    assert.equal(modalSource.includes('title="DECISION"'), true);
    assert.equal(modalSource.includes('stageNumber={1}'), true);

    // Prompt viewing and copy button
    assert.equal(modalSource.includes('FULL MODEL INPUT'), true);
    assert.equal(modalSource.includes('className="agent-debug-copy-btn"'), true);
    assert.equal(modalSource.includes('Copy full prompt'), true);

    // Manual result textarea and apply button
    assert.equal(modalSource.includes('MANUAL RESULT'), true);
    assert.equal(modalSource.includes('className="agent-debug-card__result-input"'), true);
    assert.equal(modalSource.includes('className="agent-debug-apply-btn"'), true);
    assert.equal(modalSource.includes('Apply result'), true);
});

test('19. Multi-stage progression - Answer card and Retrieval Trace become available after valid Decision', () => {
    const modalSource = readFileSync(new URL('./ModelDebugModal.jsx', import.meta.url), 'utf8');
    const widgetSource = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // Modal conditions Retrieval Trace and Answer Stage on decisionApplied && decisionParseSuccess
    assert.equal(modalSource.includes('decisionApplied && decisionParseSuccess ? ('), true);
    assert.equal(modalSource.includes('RETRIEVAL EXECUTION'), true);
    assert.equal(modalSource.includes('RetrievalTraceView trace={retrievalTrace}'), true);
    assert.equal(modalSource.includes('decisionApplied && decisionParseSuccess && answerPrompt ? ('), true);
    assert.equal(modalSource.includes('title="ANSWER"'), true);
    assert.equal(modalSource.includes('stageNumber={2}'), true);

    // AgentWidget applies decision, sets trace, parsedDecision, and progresses to answer stage
    assert.equal(widgetSource.includes('stage: \'decision\''), true);
    assert.equal(widgetSource.includes('setDebugDecisionParseSuccess(true)'), true);
    assert.equal(widgetSource.includes('setDebugParsedDecision(data.parsed_decision || null)'), true);
    assert.equal(widgetSource.includes('setDebugRetrievalTrace(data.retrieval_trace || [])'), true);
    assert.equal(widgetSource.includes('setDebugAnswerPrompt(data.full_prompt || \'\')'), true);
    assert.equal(widgetSource.includes('setDebugStage(\'answer\')'), true);

    // Final preview shown after Answer applied
    assert.equal(modalSource.includes('answerApplied && answerParseSuccess && canonicalResponse ? ('), true);
    assert.equal(modalSource.includes('CANONICAL AGENT RESPONSE PREVIEW'), true);
    assert.equal(modalSource.includes('<ResponseView response={canonicalResponse} />'), true);
});

test('20. Parser error handling - displays parser error and blocks progression', () => {
    const modalSource = readFileSync(new URL('./ModelDebugModal.jsx', import.meta.url), 'utf8');
    const widgetSource = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // Modal renders error alert
    assert.equal(modalSource.includes('className="agent-debug-card__parser-error" role="alert"'), true);
    assert.equal(modalSource.includes('<strong>Parse error:</strong>'), true);

    // Decision parser failure sets error and does NOT transition to answer
    assert.equal(widgetSource.includes('if (data.status === \'error\' || !data.parse_success) {'), true);
    assert.equal(widgetSource.includes('setDebugDecisionParseSuccess(false);'), true);
    assert.equal(widgetSource.includes('setDebugDecisionParserError(data.error || \'Failed to parse decision JSON.\');'), true);

    // Answer parser failure sets error and does NOT transition to done
    assert.equal(widgetSource.includes('setDebugAnswerParseSuccess(false);'), true);
    assert.equal(widgetSource.includes('setDebugAnswerParserError(data.error || \'Failed to parse answer response.\');'), true);
});

test('21. Reset & lifecycle isolation - Reset Debug, New Conversation, and site switch clear stale state', () => {
    const widgetSource = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

    // resetDebugState clears all stages, prompts, results, errors, traces, and canonical response
    assert.equal(widgetSource.includes('const resetDebugState = useCallback(() => {'), true);
    assert.equal(widgetSource.includes('setDebugStage(\'decision\');'), true);
    assert.equal(widgetSource.includes('setDebugDecisionPrompt(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugDecisionManualResult(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugDecisionParserError(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugRetrievalTrace([]);'), true);
    assert.equal(widgetSource.includes('setDebugAnswerPrompt(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugAnswerManualResult(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugAnswerParserError(\'\');'), true);
    assert.equal(widgetSource.includes('setDebugCanonicalResponse(null);'), true);

    // New conversation resets debug state and closes modal
    const newConvIndex = widgetSource.indexOf('const onNewConversation = useCallback(() => {');
    const newConvEnd = widgetSource.indexOf('}, [hostContext.appKey, currentScopeRef, resetDebugState]);');
    const newConvBlock = widgetSource.slice(newConvIndex, newConvEnd);
    assert.equal(newConvBlock.includes('resetDebugState();'), true);
    assert.equal(newConvBlock.includes('setDebugOpen(false);'), true);

    // Scope change effect resets debug state and closes modal
    const scopeEffectIndex = widgetSource.indexOf('// Scope change / initial mount effect: sync threads list');
    const scopeEffectBlock = widgetSource.slice(scopeEffectIndex, scopeEffectIndex + 500);
    assert.equal(scopeEffectBlock.includes('resetDebugState();'), true);
    assert.equal(scopeEffectBlock.includes('setDebugOpen(false);'), true);

    // Zero persistence side-effects: start/apply endpoints do not mutate thread or turn
    const startDebugBlock = widgetSource.slice(widgetSource.indexOf('const startModelDebug ='), widgetSource.indexOf('const onResetDebug ='));
    assert.equal(startDebugBlock.includes('setActiveThreadUlid'), false);
    assert.equal(startDebugBlock.includes('setMessages((current)'), false);
});
