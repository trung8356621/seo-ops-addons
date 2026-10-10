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
