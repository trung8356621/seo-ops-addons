import assert from 'node:assert/strict';
import test from 'node:test';
import { executedModelsText } from './testModelPresentation.js';

test('executed model presentation uses singular and plural labels', () => {
    assert.equal(executedModelsText([{ provider: 'google', model: 'gemini-2.5-flash' }]), 'Model: Gemini 2.5 Flash');
    assert.equal(executedModelsText([
        { provider: 'deepseek', model: 'deepseek-v3.2' },
        { provider: 'google', model: 'gemini-2.5-flash' },
    ]), 'Models: DeepSeek V3.2 · Gemini 2.5 Flash');
    assert.equal(executedModelsText([]), '');
});

test('provider is shown only to disambiguate duplicate model labels', () => {
    assert.equal(executedModelsText([
        { provider: 'openrouter', model: 'gemini-2.5-flash' },
        { provider: 'google', model: 'gemini-2.5-flash' },
    ]), 'Models: OpenRouter · Gemini 2.5 Flash · Google · Gemini 2.5 Flash');
});
