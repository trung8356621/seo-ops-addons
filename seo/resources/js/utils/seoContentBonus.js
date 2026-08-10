const SNIPPET_TIER_NONE = 'none';
const SNIPPET_TIER_AVERAGE = 'average';
const SNIPPET_TIER_GOOD = 'good';
const SNIPPET_TIER_EXCELLENT = 'excellent';

export const DEFAULT_FEATURED_SNIPPET_THRESHOLDS = {
    rows_min: 3,
    rows_range: 6,
    rows_max: 10,
    min_columns: 2,
    max_columns: 5,
};

const MAX_POINTS_PER_ITEM = 10;

function featuredSnippetTierFromDataRows(dataRows, thresholds) {
    if (dataRows >= thresholds.rows_max) {
        return SNIPPET_TIER_EXCELLENT;
    }

    if (dataRows >= thresholds.rows_range) {
        return SNIPPET_TIER_GOOD;
    }

    if (dataRows >= thresholds.rows_min) {
        return SNIPPET_TIER_AVERAGE;
    }

    return SNIPPET_TIER_NONE;
}

function featuredSnippetPointsForTier(tier) {
    switch (tier) {
        case SNIPPET_TIER_EXCELLENT:
            return 10;
        case SNIPPET_TIER_GOOD:
            return 6;
        case SNIPPET_TIER_AVERAGE:
            return 3;
        default:
            return 0;
    }
}

function featuredSnippetTierLabel(tier) {
    switch (tier) {
        case SNIPPET_TIER_EXCELLENT:
            return 'Rất tốt';
        case SNIPPET_TIER_GOOD:
            return 'Tốt';
        case SNIPPET_TIER_AVERAGE:
            return 'Trung bình';
        default:
            return 'Không có';
    }
}

function featuredSnippetColumnCountPasses(colCount, minCols, maxCols, hasFirstColumnDescriptor) {
    if (colCount >= minCols && colCount <= maxCols) {
        return true;
    }

    if (hasFirstColumnDescriptor && colCount > 1) {
        const effective = colCount - 1;

        return effective >= minCols && effective <= maxCols;
    }

    return false;
}

function htmlTableFeaturedSnippetMetrics(table, minCols, maxCols) {
    const rows = Array.from(table.querySelectorAll(':scope > tbody > tr, :scope > tr, :scope > thead > tr'));
    const rowColCounts = [];
    let headerRowCount = 0;
    let hasFirstColumnDescriptor = true;

    rows.forEach((row) => {
        const cells = Array.from(row.children).filter((cell) => {
            const tag = cell.tagName.toLowerCase();

            return tag === 'td' || tag === 'th';
        });

        if (cells.length === 0) {
            return;
        }

        rowColCounts.push(cells.length);

        if (cells.some((cell) => cell.tagName.toLowerCase() === 'th')) {
            headerRowCount += 1;
        }

        const firstCellText = String(cells[0]?.textContent ?? '').trim();
        if (firstCellText === '') {
            hasFirstColumnDescriptor = false;
        }
    });

    if (rowColCounts.length === 0) {
        return null;
    }

    const colCount = Math.max(...rowColCounts);
    if (!featuredSnippetColumnCountPasses(colCount, minCols, maxCols, hasFirstColumnDescriptor)) {
        return null;
    }

    const dataRowCount = rowColCounts.length - (headerRowCount > 0 ? 1 : 0);

    return {
        data_rows: Math.max(0, dataRowCount),
        columns: colCount,
    };
}

function isTopLevelHtmlTable(table) {
    let parent = table.parentElement;

    while (parent) {
        if (parent.tagName.toLowerCase() === 'table') {
            return false;
        }

        parent = parent.parentElement;
    }

    return true;
}

function collectHtmlTableMetrics(html, minCols, maxCols) {
    const source = String(html ?? '').trim();
    if (source === '' || !/<table\b/i.test(source)) {
        return [];
    }

    const container = document.createElement('div');
    container.innerHTML = source;

    const metricsList = [];

    container.querySelectorAll('table').forEach((table) => {
        if (!isTopLevelHtmlTable(table)) {
            return;
        }

        const metrics = htmlTableFeaturedSnippetMetrics(table, minCols, maxCols);
        if (metrics) {
            metricsList.push(metrics);
        }
    });

    return metricsList;
}

function collectMarkdownTableMetrics(markdown, minCols, maxCols) {
    const lines = String(markdown ?? '').split('\n');
    const metricsList = [];
    let inTable = false;
    let rowCount = 0;
    let colCount = 0;

    const flush = () => {
        if (!inTable) {
            return;
        }

        const dataRows = Math.max(0, rowCount - 1);
        if (colCount >= minCols && colCount <= maxCols) {
            metricsList.push({
                data_rows: dataRows,
                columns: colCount,
            });
        }

        inTable = false;
    };

    lines.forEach((line) => {
        const trimmed = line.trim();

        if (/\|.*\|/.test(trimmed)) {
            if (!inTable) {
                inTable = true;
                rowCount = 0;
                const cols = trimmed
                    .replace(/^\|/, '')
                    .replace(/\|$/, '')
                    .split('|')
                    .map((cell) => cell.trim())
                    .filter(Boolean);
                colCount = cols.length;
            }

            if (!/^\|?[\s\-:]+\|/.test(trimmed)) {
                rowCount += 1;
            }
        } else if (inTable) {
            flush();
        }
    });

    if (inTable) {
        flush();
    }

    return metricsList;
}

function pickFeaturedSnippetMetricsByMaxPoints(candidates, thresholds) {
    let best = null;
    let bestPoints = -1;

    candidates.forEach((metrics) => {
        const dataRows = Number(metrics.data_rows ?? 0);
        const tier = featuredSnippetTierFromDataRows(dataRows, thresholds);
        const points = featuredSnippetPointsForTier(tier);

        if (
            best === null
            || points > bestPoints
            || (points === bestPoints && dataRows > best.data_rows)
        ) {
            best = metrics;
            bestPoints = points;
        }
    });

    return best;
}

export function resolveFeaturedSnippetTableScore(html, thresholds = DEFAULT_FEATURED_SNIPPET_THRESHOLDS) {
    const minCols = thresholds.min_columns;
    const maxCols = thresholds.max_columns;
    const candidates = collectHtmlTableMetrics(html, minCols, maxCols);

    const columnLabel = `${minCols}–${maxCols} cột`;
    const tierThresholdLabel = `${thresholds.rows_min} / ${thresholds.rows_range} / ${thresholds.rows_max} dòng (trung bình / tốt / rất tốt)`;
    const metrics = pickFeaturedSnippetMetricsByMaxPoints(candidates, thresholds);

    if (!metrics) {
        return {
            tier: SNIPPET_TIER_NONE,
            passed: false,
            points: 0,
            message: `Không có bảng hoặc cột không hợp lệ (${columnLabel}). Ngưỡng: ${tierThresholdLabel}`,
        };
    }

    const dataRows = metrics.data_rows;
    const tier = featuredSnippetTierFromDataRows(dataRows, thresholds);
    const tierLabel = featuredSnippetTierLabel(tier);
    const points = featuredSnippetPointsForTier(tier);

    if (tier === SNIPPET_TIER_NONE) {
        return {
            tier,
            passed: false,
            points: 0,
            data_rows: dataRows,
            message: `Không đạt — bảng có ${dataRows} dòng dữ liệu (cần ≥ ${thresholds.rows_min} cho trung bình). ${columnLabel}, ${tierThresholdLabel}.`,
        };
    }

    return {
        tier,
        passed: tier === SNIPPET_TIER_EXCELLENT,
        points,
        data_rows: dataRows,
        message: `${tierLabel} — ${dataRows} dòng dữ liệu, ${columnLabel} (${tierThresholdLabel})`,
    };
}

function formatBonusItem(key, label, raw, faqCount = null) {
    const passed = Boolean(raw?.passed);
    const points = Math.min(MAX_POINTS_PER_ITEM, Math.max(0, Number(raw?.points ?? 0)));
    let message = String(raw?.message ?? '').trim();

    if (message === '') {
        message = passed ? `${label} đạt chuẩn.` : `${label} chưa đạt.`;
    }

    if (key === 'faq' && faqCount !== null && faqCount > 0 && !/\d+\s*câu\s*hỏi/iu.test(message)) {
        message = `${message.replace(/\.$/, '')} (${faqCount} câu hỏi).`;
    }

    return {
        key,
        label,
        points,
        max_points: MAX_POINTS_PER_ITEM,
        passed,
        message,
    };
}

function normalizeFaqs(faqs) {
    if (!Array.isArray(faqs)) {
        return [];
    }

    return faqs.filter((item) => {
        const question = String(item?.question ?? '').trim();
        const answer = String(item?.answer ?? '').trim();

        return question !== '' && answer !== '';
    });
}

export function computeContentBonus({ html = '', faqs = [], featuredSnippetThresholds = DEFAULT_FEATURED_SNIPPET_THRESHOLDS } = {}) {
    const normalizedFaqs = normalizeFaqs(faqs);
    const faqCount = normalizedFaqs.length;

    let faqRaw;
    if (faqCount > 0) {
        faqRaw = {
            passed: true,
            points: 10,
            message: `Có chứa cấu trúc FAQ chuẩn (${faqCount} câu hỏi)`,
        };
    } else {
        faqRaw = {
            passed: false,
            points: 0,
            message: 'Thiếu phần FAQ chuẩn (chưa tách / lưu FAQ)',
        };
    }

    const tableScore = resolveFeaturedSnippetTableScore(html, featuredSnippetThresholds);
    const featuredItem = formatBonusItem('featured_snippet', 'FEATURED SNIPPET', {
        passed: tableScore.passed,
        points: tableScore.points,
        message: tableScore.message,
    });
    const faqItem = formatBonusItem('faq', 'FAQ', faqRaw, faqCount);

    return {
        faq_count: faqCount,
        total_bonus: featuredItem.points + faqItem.points,
        items: {
            featured_snippet: featuredItem,
            faq: faqItem,
        },
    };
}

export { collectMarkdownTableMetrics };
