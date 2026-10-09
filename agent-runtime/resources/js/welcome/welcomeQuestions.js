export const WELCOME_MODULES = [
    {
        id: 'seo_audit',
        label: 'SEO Audit',
        tone: 'red',
        icon: 'ScanSearch',
        questions: [
            { id: 'seo_audit.worst', text: 'Những bài nào có điểm SEO thấp và nên sửa trước?', system: true },
            { id: 'seo_audit.priority', text: 'Ưu tiên tối ưu các bài SEO kém nhất trên site này.', system: true },
            { id: 'seo_audit.draft', text: 'Đưa các bài SEO điểm thấp vào Draft để cải thiện.', system: true },
        ],
    },
    {
        id: 'keywords',
        label: 'Từ khóa',
        tone: 'blue',
        icon: 'Tags',
        questions: [
            { id: 'keywords.landscape', text: 'Website này đang theo dõi những từ khóa SEO nào?', system: true },
            { id: 'keywords.coverage', text: 'Từ khóa nào chưa được bài viết trên site phủ?', system: true },
            { id: 'keywords.topics', text: 'Phân tích các bài viết gắn với nhóm chủ đề này.', system: true },
        ],
    },
    {
        id: 'content_projects',
        label: 'Dự án',
        tone: 'amber',
        icon: 'FolderKanban',
        questions: [
            { id: 'content_projects.status', text: 'Các content project trên site đang ở trạng thái nào?', system: true },
            { id: 'content_projects.drafts', text: 'Draft nào đang chờ xử lý trong dự án nội dung?', system: true },
            { id: 'content_projects.plan', text: 'Kế hoạch nội dung hiện tại của site ra sao?', system: true },
        ],
    },
    {
        id: 'gsc',
        label: 'Thống kê',
        tone: 'green',
        icon: 'ChartNoAxesCombined',
        questions: [
            { id: 'gsc.compare', text: 'So sánh hiệu suất Search Console tháng này với tháng trước.', system: true },
            { id: 'gsc.cross', text: 'Đối chiếu dữ liệu GSC với các bài có điểm SEO thấp.', system: true },
        ],
    },
    {
        id: 'articles',
        label: 'Bài viết',
        tone: 'cyan',
        icon: 'FileText',
        questions: [
            { id: 'articles.inventory', text: 'Kho bài viết trên site này hiện có những bài nào?', system: true },
            { id: 'articles.links', text: 'Site đang có những liên kết nội bộ nào?', system: true },
        ],
    },
];

export function toggleWelcomeModule(currentId, nextId) {
    return currentId === nextId ? '' : nextId;
}

export function userQuestionsPayload(modules) {
    const payload = {};
    for (const module of modules) {
        payload[module.id] = (module.questions || [])
            .filter((question) => question.system !== true)
            .map((question) => ({ id: question.id, text: question.text }));
    }
    return payload;
}

export function moveQuestion(questions, index, direction) {
    const next = index + direction;
    if (next < 0 || next >= questions.length) {
        return questions;
    }
    const copy = questions.slice();
    const [item] = copy.splice(index, 1);
    copy.splice(next, 0, item);
    return copy;
}
