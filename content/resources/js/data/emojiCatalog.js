/**
 * Danh mục emoji cho editor bài viết SEO.
 * @type {{ id: string, label: string, items: { emoji: string, label: string, keywords: string[] }[] }[]}
 */
export const EMOJI_CATEGORIES = [
    {
        id: 'office',
        label: 'Văn phòng & nội dung',
        items: [
            { emoji: '📝', label: 'Ghi chú', keywords: ['note', 'memo', 'write'] },
            { emoji: '📋', label: 'Clipboard', keywords: ['clipboard', 'list'] },
            { emoji: '📌', label: 'Ghim', keywords: ['pin', 'pushpin'] },
            { emoji: '📎', label: 'Kẹp giấy', keywords: ['clip', 'paperclip'] },
            { emoji: '✏️', label: 'Bút chì', keywords: ['pencil', 'edit'] },
            { emoji: '📊', label: 'Biểu đồ', keywords: ['chart', 'stats'] },
            { emoji: '📈', label: 'Tăng trưởng', keywords: ['growth', 'up'] },
            { emoji: '📉', label: 'Giảm', keywords: ['down', 'decline'] },
            { emoji: '📖', label: 'Sách mở', keywords: ['book', 'read'] },
            { emoji: '📘', label: 'Sách xanh', keywords: ['book', 'blue'] },
            { emoji: '📗', label: 'Sách xanh lá', keywords: ['book', 'green'] },
            { emoji: '💼', label: 'Công việc', keywords: ['briefcase', 'work'] },
        ],
    },
    {
        id: 'creative',
        label: 'Sáng tạo & media',
        items: [
            { emoji: '🎨', label: 'Palette', keywords: ['art', 'design', 'color'] },
            { emoji: '🖌️', label: 'Cọ vẽ', keywords: ['brush', 'paint'] },
            { emoji: '🖼️', label: 'Khung ảnh', keywords: ['frame', 'image'] },
            { emoji: '📷', label: 'Máy ảnh', keywords: ['camera', 'photo'] },
            { emoji: '🎬', label: 'Phim', keywords: ['movie', 'video'] },
            { emoji: '🎵', label: 'Nhạc', keywords: ['music', 'audio'] },
            { emoji: '✨', label: 'Lấp lánh', keywords: ['sparkle', 'new'] },
            { emoji: '💫', label: 'Sao bay', keywords: ['dizzy', 'star'] },
        ],
    },
    {
        id: 'faces',
        label: 'Mặt cười & cảm xúc',
        items: [
            { emoji: '😀', label: 'Cười', keywords: ['smile', 'happy'] },
            { emoji: '😃', label: 'Vui', keywords: ['grin', 'happy'] },
            { emoji: '😄', label: 'Cười to', keywords: ['laugh', 'joy'] },
            { emoji: '😆', label: 'Cười ngặt', keywords: ['laugh', 'squint'] },
            { emoji: '🙂', label: 'Mỉm cười', keywords: ['slight', 'smile'] },
            { emoji: '😉', label: 'Nháy mắt', keywords: ['wink'] },
            { emoji: '😊', label: 'Ngại', keywords: ['blush', 'shy'] },
            { emoji: '🤔', label: 'Suy nghĩ', keywords: ['think', 'hmm'] },
            { emoji: '😎', label: 'Cool', keywords: ['cool', 'sunglasses'] },
            { emoji: '🥳', label: 'Party', keywords: ['party', 'celebrate'] },
            { emoji: '😍', label: 'Yêu', keywords: ['love', 'heart eyes'] },
            { emoji: '👍', label: 'Like', keywords: ['thumbs', 'up', 'ok'] },
            { emoji: '👏', label: 'Vỗ tay', keywords: ['clap', 'applause'] },
            { emoji: '🙌', label: 'Giơ tay', keywords: ['raise', 'hands'] },
            { emoji: '❤️', label: 'Tim', keywords: ['heart', 'love'] },
            { emoji: '🔥', label: 'Hot', keywords: ['fire', 'hot', 'trending'] },
        ],
    },
    {
        id: 'status',
        label: 'Trạng thái & nhấn mạnh',
        items: [
            { emoji: '✅', label: 'Đúng / xong', keywords: ['check', 'done', 'yes'] },
            { emoji: '❌', label: 'Sai / hủy', keywords: ['cross', 'no', 'wrong'] },
            { emoji: '⚠️', label: 'Cảnh báo', keywords: ['warning', 'alert'] },
            { emoji: '💡', label: 'Ý tưởng', keywords: ['idea', 'bulb', 'tip'] },
            { emoji: '❗', label: 'Quan trọng', keywords: ['exclamation', 'important'] },
            { emoji: '❓', label: 'Câu hỏi', keywords: ['question', 'faq'] },
            { emoji: '⭐', label: 'Sao', keywords: ['star', 'rating'] },
            { emoji: '🏆', label: 'Cúp', keywords: ['trophy', 'win', 'best'] },
            { emoji: '🎁', label: 'Quà', keywords: ['gift', 'present'] },
            { emoji: '🏷️', label: 'Nhãn giá', keywords: ['tag', 'label', 'sale'] },
            { emoji: '💰', label: 'Tiền', keywords: ['money', 'price'] },
            { emoji: '🛒', label: 'Giỏ hàng', keywords: ['cart', 'shop', 'buy'] },
        ],
    },
    {
        id: 'steps',
        label: 'Bước & mũi tên',
        items: [
            { emoji: '1️⃣', label: 'Bước 1', keywords: ['one', 'step'] },
            { emoji: '2️⃣', label: 'Bước 2', keywords: ['two', 'step'] },
            { emoji: '3️⃣', label: 'Bước 3', keywords: ['three', 'step'] },
            { emoji: '4️⃣', label: 'Bước 4', keywords: ['four', 'step'] },
            { emoji: '👉', label: 'Chỉ tay', keywords: ['point', 'right'] },
            { emoji: '👈', label: 'Chỉ trái', keywords: ['point', 'left'] },
            { emoji: '➡️', label: 'Mũi tên phải', keywords: ['arrow', 'right'] },
            { emoji: '⬅️', label: 'Mũi tên trái', keywords: ['arrow', 'left'] },
            { emoji: '▶️', label: 'Play', keywords: ['play', 'next'] },
            { emoji: '⏩', label: 'Nhanh', keywords: ['fast', 'forward'] },
        ],
    },
    {
        id: 'contact',
        label: 'Liên hệ & web',
        items: [
            { emoji: '📞', label: 'Điện thoại', keywords: ['phone', 'call'] },
            { emoji: '📧', label: 'Email', keywords: ['mail', 'email'] },
            { emoji: '🌐', label: 'Website', keywords: ['web', 'globe', 'internet'] },
            { emoji: '📍', label: 'Vị trí', keywords: ['location', 'pin', 'map'] },
            { emoji: '🗓️', label: 'Lịch', keywords: ['calendar', 'date'] },
            { emoji: '⏰', label: 'Giờ', keywords: ['clock', 'time'] },
            { emoji: '🔔', label: 'Thông báo', keywords: ['bell', 'notify'] },
        ],
    },
    {
        id: 'nature',
        label: 'Thiên nhiên',
        items: [
            { emoji: '🌱', label: 'Mầm', keywords: ['plant', 'eco', 'green'] },
            { emoji: '🌿', label: 'Lá', keywords: ['leaf', 'nature'] },
            { emoji: '☀️', label: 'Mặt trời', keywords: ['sun', 'sunny'] },
            { emoji: '🌍', label: 'Trái đất', keywords: ['earth', 'world'] },
            { emoji: '💧', label: 'Nước', keywords: ['water', 'drop'] },
        ],
    },
];

const ALL_EMOJIS = EMOJI_CATEGORIES.flatMap((cat) =>
    cat.items.map((item) => ({ ...item, categoryId: cat.id, categoryLabel: cat.label })),
);

/**
 * @param {string} query
 * @returns {{ id: string, label: string, items: typeof EMOJI_CATEGORIES[0]['items'] }[]}
 */
export function filterEmojiCategories(query) {
    const q = String(query || '')
        .trim()
        .toLowerCase();

    if (!q) {
        return EMOJI_CATEGORIES;
    }

    return EMOJI_CATEGORIES.map((cat) => ({
        ...cat,
        items: cat.items.filter(
            (item) =>
                item.emoji.includes(q) ||
                item.label.toLowerCase().includes(q) ||
                cat.label.toLowerCase().includes(q) ||
                item.keywords.some((k) => k.toLowerCase().includes(q)),
        ),
    })).filter((cat) => cat.items.length > 0);
}

export function getAllEmojis() {
    return ALL_EMOJIS;
}
