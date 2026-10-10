export function reviewCandidates(response) {
    const candidates = response?.execution?.routing_review?.candidates;
    return (Array.isArray(candidates) ? candidates : []).filter((candidate) => (
        candidate?.id && String(candidate?.question || '').trim() !== ''
    ));
}

export function soleAgentChoice(candidates) {
    const selected = (Array.isArray(candidates) ? candidates : []).filter((candidate) => (
        candidate?.selected === true && candidate?.id
    ));
    return selected.length === 1 ? String(selected[0].id) : '';
}

export function defaultReviewChoice(savedChoice, candidates) {
    const saved = typeof savedChoice === 'string' ? savedChoice.trim() : '';
    if (saved !== '') {
        return saved;
    }
    return soleAgentChoice(candidates);
}

export function completedReviewRun(response) {
    if (!response || response.status === 'paused') {
        return null;
    }
    const runUlid = String(response.run_ulid || '').trim();
    if (runUlid === '' || reviewCandidates(response).length === 0) {
        return null;
    }
    return runUlid;
}

export function findReviewResponse(messages, runUlid) {
    if (!runUlid) {
        return null;
    }
    const list = Array.isArray(messages) ? messages : [];
    for (let index = list.length - 1; index >= 0; index -= 1) {
        const response = list[index]?.response;
        if (response?.run_ulid === runUlid) {
            return response;
        }
    }
    return null;
}

export function toggleReviewRun(openRunUlid, runUlid) {
    if (!runUlid) {
        return openRunUlid || null;
    }
    return openRunUlid === runUlid ? null : runUlid;
}

export function resolveReviewIndicator(response, confirmedChoice) {
    const choice = typeof confirmedChoice === 'string' ? confirmedChoice.trim() : '';
    if (choice === '') {
        return null;
    }
    if (choice === '__none__') {
        return {
            kind: 'none',
            title: 'Bạn đã chọn: Không câu nào đúng ý tôi',
        };
    }

    const candidates = response?.execution?.routing_review?.candidates;
    const chosen = (Array.isArray(candidates) ? candidates : []).find((candidate) => candidate?.id === choice);
    const question = String(chosen?.question || '').trim();
    if (question === '') {
        return null;
    }
    if (chosen.selected === true) {
        return {
            kind: 'agreed',
            title: `Đã chọn: ${question}`,
        };
    }

    return {
        kind: 'alternative',
        title: `Đã chọn cách hiểu khác: ${question}`,
    };
}
