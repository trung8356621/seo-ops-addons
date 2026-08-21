import React, { useState } from 'react';
import { ChevronDown, ChevronRight, AlertCircle, CheckCircle2 } from 'lucide-react';
import { t } from '@content-addon/utils/i18n.js';
import {
    buildFailedViolationItems,
    buildPassedRuleItems,
    sanitizeViolations,
    scoreFromViolations,
    scoreQualityLabel,
} from '../utils/seoScoreCalculator';
import { resolveSeoViolationAction } from '../utils/seoViolationActions';

function scoreColor(score) {
    if (score >= 70) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-rose-600 dark:text-rose-400';
}

function scoreBadgeClass(score) {
    if (score >= 70) return 'seo-assistant-score__badge--good';
    if (score >= 50) return 'seo-assistant-score__badge--fair';
    return 'seo-assistant-score__badge--poor';
}

function ViolationActionButton({ item, onViolationAction, canGenerateFaq = true, canGenerateFeaturedSnippet = true }) {
    const action = resolveSeoViolationAction(item.key);
    if (!action || typeof onViolationAction !== 'function') {
        return null;
    }
    if (action.action === 'open-faq-generator' && !canGenerateFaq) {
        return null;
    }
    if (action.action === 'open-featured-snippet-prompt' && !canGenerateFeaturedSnippet) {
        return null;
    }

    return (
        <button
            type="button"
            className="seo-assistant-score__issue-action"
            onClick={() => onViolationAction(action)}
        >
            {t(action.labelKey)}
        </button>
    );
}

export default function SeoScorePanel({
    focusKeyword,
    analysis,
    seoScoringRules = [],
    seoRuleMessages = {},
    loading,
    analyzing,
    stale = false,
    ready = false,
    analyzeError = null,
    onAnalyzeClick,
    onViolationAction,
    canGenerateFaq = true,
    canGenerateFeaturedSnippet = true,
    savedScore = null,
    scoreSource = 'live',
    syncRequired = false,
    unavailableMessage = null,
}) {
    const [passedOpen, setPassedOpen] = useState(true);
    const rules = Array.isArray(seoScoringRules) && seoScoringRules.length > 0
        ? seoScoringRules
        : (Array.isArray(window.__SEO_SCORING_RULES__) ? window.__SEO_SCORING_RULES__ : []);
    const isLoading = loading || analyzing;
    const isReady = ready === true && !isLoading && !syncRequired;

    if (syncRequired) {
        return (
            <div className="seo-score-panel seo-assistant-score seo-assistant-score--unanalyzed">
                <p className="seo-assistant-score__hint">
                    {unavailableMessage || t('content_sync_required_seo')}
                </p>
            </div>
        );
    }

    if (!isReady && !isLoading) {
        return (
            <div className="seo-score-panel seo-assistant-score seo-assistant-score--unanalyzed">
                <p className="seo-assistant-score__hint">
                    {analyzeError
                        ? t('editor_seo_analyze_failed')
                        : t('editor_seo_unanalyzed')}
                </p>
                <button
                    type="button"
                    className="seo-assistant-score__stale-hint"
                    onClick={onAnalyzeClick}
                >
                    {analyzeError ? t('editor_seo_analyze_retry') : t('editor_seo_update_score')}
                </button>
            </div>
        );
    }

    const violations = sanitizeViolations(
        Array.isArray(analysis?.violations) ? analysis.violations : [],
        rules,
    );
    const messages = Object.keys(seoRuleMessages).length > 0 ? seoRuleMessages : (analysis?.messages ?? {});

    const score = scoreFromViolations(violations, rules);
    const metrics = analysis?.metrics ?? {};
    const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
    const failedItems = buildFailedViolationItems(violations, rules, messages, metrics, locale);
    const passedItems = buildPassedRuleItems(violations, rules, messages);
    const quality = scoreQualityLabel(score);
    const saved = savedScore === null || savedScore === undefined ? null : Number(savedScore);
    const showSavedDiff = Number.isFinite(saved) && saved !== score;

    return (
        <div className="seo-score-panel seo-assistant-score">
            <div className="seo-assistant-score__hero">
                <div className={`seo-assistant-score__value ${scoreColor(score)}`}>
                    {isLoading ? '…' : (
                        <>
                            <span className="seo-assistant-score__number">{score}</span>
                            <span className="seo-assistant-score__max">/ 100</span>
                        </>
                    )}
                </div>
                <span className={`seo-assistant-score__badge ${scoreBadgeClass(score)}`}>
                    {isLoading ? t('seo_score_analyzing') : quality}
                </span>
            </div>

            <p className="seo-assistant-score__hint">
                {analyzing
                    ? t('editor_seo_analyzing')
                    : analyzeError
                      ? t('editor_seo_analyze_failed')
                      : scoreSource === 'saved'
                        ? t('editor_seo_saved_score_hint')
                        : t('editor_seo_live_score_hint')}
            </p>

            {showSavedDiff && !analyzing ? (
                <p className="seo-assistant-score__saved-diff">
                    {t('editor_seo_saved_score_label')}: {saved}/100
                    {' · '}
                    {t('editor_seo_live_score_label')}: {score}/100
                </p>
            ) : null}

            {analyzeError && !analyzing ? (
                <button
                    type="button"
                    className="seo-assistant-score__stale-hint"
                    onClick={onAnalyzeClick}
                >
                    {t('editor_seo_analyze_retry')}
                </button>
            ) : null}

            {stale && !analyzing && !analyzeError ? (
                <button
                    type="button"
                    className="seo-assistant-score__stale-hint"
                    onClick={onAnalyzeClick}
                >
                    {t('editor_seo_stale')}
                </button>
            ) : null}

            {!analyzing ? (
                <button
                    type="button"
                    className="seo-assistant-score__stale-hint"
                    onClick={onAnalyzeClick}
                >
                    {t('editor_seo_update_score')}
                </button>
            ) : null}

            {focusKeyword ? (
                <p className="seo-assistant-score__keyword">
                    <span className="text-gray-500 dark:text-gray-400">Focus keyword:</span>{' '}
                    <strong className="text-gray-900 dark:text-white">{focusKeyword}</strong>
                </p>
            ) : (
                <p className="seo-assistant-score__keyword seo-assistant-score__keyword--missing">
                    {t('seo_score_missing_focus_keyword')}
                </p>
            )}

            {failedItems.length > 0 ? (
                <ul className="seo-assistant-score__issues">
                    {failedItems.map((item) => (
                        <li
                            key={item.key}
                            className="seo-assistant-score__issue"
                            title={item.detail || item.label}
                            data-seo-violation-key={item.key}
                        >
                            <AlertCircle size={14} className="seo-assistant-score__issue-icon" aria-hidden />
                            <span className="seo-assistant-score__issue-label">
                                {item.summary || item.label}
                                {item.detail && item.detail !== item.summary ? (
                                    <span className="seo-assistant-score__issue-detail">{item.detail}</span>
                                ) : null}
                            </span>
                            <span className="seo-assistant-score__issue-deduction">(-{item.deduction})</span>
                            <ViolationActionButton
                                item={item}
                                onViolationAction={onViolationAction}
                                canGenerateFaq={canGenerateFaq}
                                canGenerateFeaturedSnippet={canGenerateFeaturedSnippet}
                            />
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="seo-assistant-score__all-passed">{t('seo_score_all_passed')}</p>
            )}

            {passedItems.length > 0 ? (
                <div className="seo-assistant-score__passed">
                    <button
                        type="button"
                        className="seo-assistant-score__passed-toggle"
                        aria-expanded={passedOpen}
                        onClick={() => setPassedOpen((open) => !open)}
                    >
                        {passedOpen ? (
                            <ChevronDown size={15} aria-hidden />
                        ) : (
                            <ChevronRight size={15} aria-hidden />
                        )}
                        <span>Passed Checks</span>
                        <span className="seo-assistant-widget__badge">{passedItems.length}</span>
                    </button>
                    {passedOpen ? (
                        <ul className="seo-assistant-score__passed-list">
                            {passedItems.map((item) => (
                                <li key={item.key} className="seo-assistant-score__passed-item">
                                    <span className="seo-assistant-score__passed-icon-wrap" aria-hidden>
                                        <CheckCircle2 size={13} />
                                    </span>
                                    <span className="seo-assistant-score__passed-label">{item.label}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
