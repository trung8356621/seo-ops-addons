import { useState } from 'react';
import { AlertTriangle, Check, Copy } from 'lucide-react';
import { ToolTrace } from '../response/ResponseBlocks.jsx';
import { copyPlainText } from './clipboard.js';

function AssumedModelHeader({ assumedModel }) {
    if (!assumedModel) return null;
    const { provider, model, display_name, profile, fallbacks, routing_mode, status } = assumedModel;
    return (
        <div className="agent-debug-card__model-meta">
            <div className="agent-debug-card__model-line">
                <span className="agent-debug-card__model-label">Assumed model:</span>
                <span className="agent-debug-card__model-value">
                    {status === 'available' ? `${provider ? `${provider} / ` : ''}${display_name || model}` : 'Not configured in AI Settings'}
                </span>
            </div>
            <div className="agent-debug-card__routing-line">
                <span className="agent-debug-card__routing-label">Routing / profile:</span>
                <span className="agent-debug-card__routing-value">{profile || routing_mode}</span>
                {Array.isArray(fallbacks) && fallbacks.length ? <span className="agent-debug-card__fallbacks">Fallbacks: {fallbacks.length} candidates</span> : null}
            </div>
            {Array.isArray(fallbacks) && fallbacks.length ? (
                <details className="agent-debug-card__fallback-details">
                    <summary>Show fallback candidates</summary>
                    <div>{fallbacks.map((item) => item.display_name || item.model).join(', ')}</div>
                </details>
            ) : null}
        </div>
    );
}

export function ModelDebugModal({ isOpen, scopeLabel, modelCall, manualResult, onManualResultChange, onApply, isApplying, parserError }) {
    const [copiedChat, setCopiedChat] = useState(false);
    const [copiedRaw, setCopiedRaw] = useState(false);
    const [copyError, setCopyError] = useState('');
    if (!isOpen || !modelCall) return null;

    async function copyChatPrompt() {
        setCopiedChat(false);
        setCopyError('');
        try {
            await copyPlainText(modelCall.chat_prompt || modelCall.full_prompt || '');
            setCopiedChat(true);
            window.setTimeout(() => setCopiedChat(false), 1500);
        } catch {
            setCopyError('Could not copy. Select the prompt and copy it manually.');
        }
    }

    async function copyRawPrompt() {
        setCopiedRaw(false);
        setCopyError('');
        try {
            await copyPlainText(modelCall.full_prompt || '');
            setCopiedRaw(true);
            window.setTimeout(() => setCopiedRaw(false), 1500);
        } catch {
            setCopyError('Could not copy. Select the prompt and copy it manually.');
        }
    }

    return (
        <div className="agent-debug-overlay" role="dialog" aria-modal="true" aria-label="Model Debug">
            <div className="agent-debug-modal">
                <div className="agent-debug-modal__header">
                    <div className="agent-debug-modal__header-left">
                        <h2>MODEL DEBUG · {String(modelCall.key || 'model').toUpperCase()}</h2>
                        <span className="agent-debug-modal__badge">PRODUCTION TURN · MANUAL COMPLETION</span>
                        <span className="agent-debug-modal__scope">{scopeLabel}</span>
                    </div>
                </div>
                <div className="agent-debug-modal__body">
                <div className={`agent-debug-card ${parserError ? 'agent-debug-card--error' : ''}`}>
                    <div className="agent-debug-card__header">
                        <h3>{String(modelCall.key || 'model').toUpperCase()} MODEL CALL</h3>
                        <span className="agent-debug-card__size">{modelCall.prompt_size || 0} chars</span>
                    </div>
                    <AssumedModelHeader assumedModel={modelCall.assumed_model} />
                    <div className="agent-debug-card__section">
                        <div className="agent-debug-card__section-header">
                            <span className="agent-debug-card__section-title">FULL MODEL INPUT</span>
                            <div className="agent-debug-card__actions-group" style={{ display: 'flex', gap: '8px' }}>
                                <button type="button" className="agent-debug-copy-btn agent-debug-copy-btn--primary" onClick={copyChatPrompt} title="Prompt đã tối ưu cho Gemini Chat">
                                    {copiedChat ? <Check size={14} /> : <Copy size={14} />}
                                    <span>{copiedChat ? 'Đã sao chép cho Gemini Chat' : 'Copy for Gemini Chat'}</span>
                                </button>
                                <button type="button" className="agent-debug-copy-btn" onClick={copyRawPrompt} title="Dữ liệu thô gửi đến model API">
                                    {copiedRaw ? <Check size={14} /> : <Copy size={14} />}
                                    <span>{copiedRaw ? 'Đã chép raw' : 'Copy raw input'}</span>
                                </button>
                            </div>
                        </div>
                        {copyError ? <p className="agent-debug-copy-error" role="alert">{copyError}</p> : null}
                        <textarea className="agent-debug-card__prompt-view" readOnly value={modelCall.full_prompt || ''} rows={10} />
                    </div>
                    <div className="agent-debug-card__section">
                        <div className="agent-debug-card__section-header">
                            <span className="agent-debug-card__section-title">MANUAL RESULT</span>
                            <small className="agent-debug-card__hint">Dán toàn bộ JSON mà Gemini trả về, không chỉ phần nội dung câu trả lời.</small>
                        </div>
                        <textarea className="agent-debug-card__result-input" value={manualResult} onChange={(event) => onManualResultChange(event.target.value)} rows={8} disabled={isApplying} placeholder='{"message": "...", "blocks": [...], "actions": [...]}' />
                    </div>
                    {modelCall.execution ? <ToolTrace response={{ execution: modelCall.execution }} debug /> : null}
                    {parserError ? (
                        <div className="agent-debug-card__parser-error" role="alert"><AlertTriangle size={15} /><p>{parserError}</p></div>
                    ) : null}
                    <div className="agent-debug-card__actions">
                        <button type="button" className="agent-debug-apply-btn" onClick={onApply} disabled={isApplying || !manualResult.trim()}>
                            {isApplying ? 'Applying...' : 'Apply'}
                        </button>
                    </div>
                </div>
                </div>
            </div>
        </div>
    );
}

export default ModelDebugModal;
