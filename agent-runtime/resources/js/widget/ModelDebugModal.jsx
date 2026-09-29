import { useState } from 'react';
import { Check, Copy, AlertTriangle, ArrowRight, CornerDownRight, X, RotateCcw } from 'lucide-react';
import { ResponseView } from '../response/ResponseBlocks.jsx';

function AssumedModelHeader({ assumedModel }) {
    if (!assumedModel) {
        return null;
    }

    const { stage, provider, model, display_name, profile, fallbacks, routing_mode, status } = assumedModel;
    const isAvailable = status === 'available';

    return (
        <div className="agent-debug-card__model-meta">
            <div className="agent-debug-card__model-line">
                <span className="agent-debug-card__model-label">Assumed model:</span>
                <span className="agent-debug-card__model-value">
                    {isAvailable ? `${provider ? `${provider} / ` : ''}${display_name || model}` : 'Not configured in AI Settings'}
                </span>
            </div>
            <div className="agent-debug-card__routing-line">
                <span className="agent-debug-card__routing-label">Routing / profile:</span>
                <span className="agent-debug-card__routing-value">{profile || routing_mode}</span>
                {Array.isArray(fallbacks) && fallbacks.length > 0 ? (
                    <span className="agent-debug-card__fallbacks">
                        (Fallbacks: {fallbacks.map((f) => f.display_name || f.model).join(', ')})
                    </span>
                ) : null}
            </div>
        </div>
    );
}

function StageCard({
    title,
    stageNumber,
    assumedModel,
    fullPrompt,
    promptSize,
    manualResult,
    onManualResultChange,
    onApply,
    isApplying,
    parseSuccess,
    parserError,
    applied,
}) {
    const [copied, setCopied] = useState(false);

    async function handleCopy() {
        if (!fullPrompt) return;
        await navigator.clipboard.writeText(fullPrompt);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    return (
        <div className={`agent-debug-card ${applied ? 'agent-debug-card--applied' : ''} ${parserError ? 'agent-debug-card--error' : ''}`}>
            <div className="agent-debug-card__header">
                <div className="agent-debug-card__title">
                    <span className="agent-debug-card__step">{stageNumber}</span>
                    <h3>{title}</h3>
                </div>
                {promptSize ? (
                    <span className="agent-debug-card__size">{promptSize} chars</span>
                ) : null}
            </div>

            <AssumedModelHeader assumedModel={assumedModel} />

            <div className="agent-debug-card__section">
                <div className="agent-debug-card__section-header">
                    <span className="agent-debug-card__section-title">FULL MODEL INPUT</span>
                    <button
                        type="button"
                        className="agent-debug-copy-btn"
                        onClick={handleCopy}
                        disabled={!fullPrompt}
                    >
                        {copied ? <Check size={14} /> : <Copy size={14} />}
                        <span>{copied ? 'Copied' : 'Copy full prompt'}</span>
                    </button>
                </div>
                <textarea
                    className="agent-debug-card__prompt-view"
                    readOnly
                    value={fullPrompt || ''}
                    rows={8}
                />
            </div>

            <div className="agent-debug-card__section">
                <div className="agent-debug-card__section-header">
                    <span className="agent-debug-card__section-title">MANUAL RESULT</span>
                    <small className="agent-debug-card__hint">Paste output from web model</small>
                </div>
                <textarea
                    className="agent-debug-card__result-input"
                    value={manualResult}
                    onChange={(e) => onManualResultChange(e.target.value)}
                    placeholder="Paste raw JSON or completion result here..."
                    rows={6}
                    disabled={isApplying}
                />
            </div>

            {parserError ? (
                <div className="agent-debug-card__parser-error" role="alert">
                    <AlertTriangle size={15} />
                    <div>
                        <strong>Parse error:</strong>
                        <p>{parserError}</p>
                    </div>
                </div>
            ) : null}

            {parseSuccess && applied ? (
                <div className="agent-debug-card__parser-success">
                    <Check size={15} />
                    <span>Parsed successfully</span>
                </div>
            ) : null}

            <div className="agent-debug-card__actions">
                <button
                    type="button"
                    className="agent-debug-apply-btn"
                    onClick={onApply}
                    disabled={isApplying || !manualResult.trim()}
                >
                    {isApplying ? 'Applying...' : 'Apply result'}
                </button>
            </div>
        </div>
    );
}

function RetrievalTraceView({ trace, decision }) {
    if (!trace || !Array.isArray(trace) || trace.length === 0) {
        return (
            <div className="agent-debug-trace agent-debug-trace--empty">
                <span className="agent-debug-trace__title">RETRIEVAL TRACE</span>
                <p>No resources requested or fetched.</p>
            </div>
        );
    }

    return (
        <div className="agent-debug-trace">
            <div className="agent-debug-trace__header">
                <span className="agent-debug-trace__title">RETRIEVAL TRACE (READ-ONLY)</span>
                {decision ? (
                    <span className="agent-debug-trace__intent">Intent: {decision.intent}</span>
                ) : null}
            </div>
            <ul className="agent-debug-trace__list">
                {trace.map((item, index) => (
                    <li key={index} className={`agent-debug-trace__item is-${item.status}`}>
                        <span className="agent-debug-trace__resource">{item.name}</span>
                        <code className="agent-debug-trace__request">{item.request}</code>
                        <span className="agent-debug-trace__status">[{item.status}]</span>
                        {item.reason ? (
                            <span className="agent-debug-trace__reason">reason: {item.reason}</span>
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function ModelDebugModal({
    isOpen,
    onClose,
    onReset,
    scopeLabel,
    userMessage,
    // Decision stage
    decisionPrompt,
    decisionPromptSize,
    decisionAssumedModel,
    decisionManualResult,
    onDecisionManualResultChange,
    onApplyDecision,
    isApplyingDecision,
    decisionParseSuccess,
    decisionParserError,
    decisionApplied,
    // Retrieval trace
    retrievalTrace,
    parsedDecision,
    // Answer stage
    answerPrompt,
    answerPromptSize,
    answerAssumedModel,
    answerManualResult,
    onAnswerManualResultChange,
    onApplyAnswer,
    isApplyingAnswer,
    answerParseSuccess,
    answerParserError,
    answerApplied,
    // Final preview
    canonicalResponse,
}) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="agent-debug-overlay" role="dialog" aria-modal="true" aria-label="Model Debug">
            <div className="agent-debug-modal">
                <div className="agent-debug-modal__header">
                    <div className="agent-debug-modal__header-left">
                        <h2>MODEL DEBUG</h2>
                        <span className="agent-debug-modal__badge">SIMULATOR · ZERO TOKENS</span>
                        <span className="agent-debug-modal__scope">{scopeLabel}</span>
                    </div>
                    <div className="agent-debug-modal__header-right">
                        <button
                            type="button"
                            className="agent-debug-btn agent-debug-reset-btn"
                            onClick={onReset}
                            title="Reset debug session"
                        >
                            <RotateCcw size={14} />
                            <span>Reset Debug</span>
                        </button>
                        <button
                            type="button"
                            className="agent-debug-btn agent-debug-close-btn"
                            onClick={onClose}
                            title="Close modal"
                            aria-label="Close"
                        >
                            <X size={18} />
                        </button>
                    </div>
                </div>

                {userMessage ? (
                    <div className="agent-debug-modal__context">
                        <span className="agent-debug-modal__context-label">Current prompt / question:</span>
                        <p className="agent-debug-modal__context-message">{userMessage}</p>
                    </div>
                ) : null}

                <div className="agent-debug-modal__body">
                    {/* Stage 1: Decision */}
                    <StageCard
                        title="DECISION"
                        stageNumber={1}
                        assumedModel={decisionAssumedModel}
                        fullPrompt={decisionPrompt}
                        promptSize={decisionPromptSize}
                        manualResult={decisionManualResult}
                        onManualResultChange={onDecisionManualResultChange}
                        onApply={onApplyDecision}
                        isApplying={isApplyingDecision}
                        parseSuccess={decisionParseSuccess}
                        parserError={decisionParserError}
                        applied={decisionApplied}
                    />

                    {/* Step 2: Retrieval Trace (visible once Decision applied) */}
                    {decisionApplied && decisionParseSuccess ? (
                        <>
                            <div className="agent-debug-divider">
                                <ArrowRight size={16} />
                                <span>RETRIEVAL EXECUTION</span>
                            </div>
                            <RetrievalTraceView trace={retrievalTrace} decision={parsedDecision} />
                        </>
                    ) : null}

                    {/* Stage 2: Answer (available once Decision applied successfully) */}
                    {decisionApplied && decisionParseSuccess && answerPrompt ? (
                        <>
                            <div className="agent-debug-divider">
                                <ArrowRight size={16} />
                                <span>ANSWER MODEL INPUT</span>
                            </div>
                            <StageCard
                                title="ANSWER"
                                stageNumber={2}
                                assumedModel={answerAssumedModel}
                                fullPrompt={answerPrompt}
                                promptSize={answerPromptSize}
                                manualResult={answerManualResult}
                                onManualResultChange={onAnswerManualResultChange}
                                onApply={onApplyAnswer}
                                isApplying={isApplyingAnswer}
                                parseSuccess={answerParseSuccess}
                                parserError={answerParserError}
                                applied={answerApplied}
                            />
                        </>
                    ) : null}

                    {/* Final Preview: Parsed AgentResponse Preview */}
                    {answerApplied && answerParseSuccess && canonicalResponse ? (
                        <>
                            <div className="agent-debug-divider">
                                <CornerDownRight size={16} />
                                <span>CANONICAL AGENT RESPONSE PREVIEW</span>
                            </div>
                            <div className="agent-debug-preview">
                                <div className="agent-debug-preview__header">
                                    <span>Preview Only · Not Persisted</span>
                                </div>
                                <div className="agent-debug-preview__body">
                                    <ResponseView response={canonicalResponse} />
                                </div>
                            </div>
                        </>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default ModelDebugModal;
