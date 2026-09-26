// Generated bilingual catalog for UI strings migrated by the i18n audit.
const messages = {
  "en": {
    "audit_57fef64c16e6": "New SEO process",
    "audit_8a09e03d2052": "Come back",
    "audit_e2592ea8a6bd": "Process saved successfully.",
    "audit_375c8977f76f": "Unable to save process.",
    "audit_a1087ea6cb7d": "Save process too long. Please try again.",
    "audit_50c9716920b6": "Do not assign roles",
    "audit_109e53ae6726": "Create an outline",
    "audit_82c2dbf0a17e": "Write articles",
    "audit_9648d56af475": "Improve articles",
    "audit_5c6805fbd29f": "Create images",
    "audit_6507b676a08f": "Create/update posts",
    "audit_ce2e6e056f80": "Filter posts",
    "audit_063670e1bf6e": "Saving...",
    "audit_f150199efd1f": "Save the Process Diagram",
    "audit_a2b1f3c58451": "Zoom out",
    "audit_5d000cd701c3": "Zoom out the diagram",
    "audit_cbfde1f64850": "Reset 100%",
    "audit_affed1defc54": "Enlarge",
    "audit_24a3087ee36b": "Enlarge the diagram",
    "audit_7028dfd41751": "— Use main Prompt Block —",
    "audit_5c31fd91e1a4": "— Select vocabulary prompt —",
    "audit_294dcf75549f": "0. Dissection by Tag [START...END]",
    "audit_6ff7fd5ab1cc": "Filter custom conditions",
    "audit_7cd3f724ca7f": "1. Extract Outline (Markdown -> JSON)",
    "audit_d158cfafd9f0": "2. Keyword Extraction (Markdown -> JSON)",
    "audit_9915fb6644fc": "3. Dissecting the FAQ",
    "audit_7a8c30135183": "4. SEO Scoring (FAQ + Table)",
    "audit_64ab5110f629": "Select tags...",
    "audit_9cc08c0c5759": "Save vocabulary research (Topic Cluster)",
    "audit_b82a2e8527f3": "Post comments/reviews (WordPress)"
  },
  "vi": {
    "audit_57fef64c16e6": "Quy trình SEO mới",
    "audit_8a09e03d2052": "Quay lại",
    "audit_e2592ea8a6bd": "Đã lưu quy trình thành công.",
    "audit_375c8977f76f": "Không thể lưu quy trình.",
    "audit_a1087ea6cb7d": "Lưu quy trình quá lâu. Vui lòng thử lại.",
    "audit_50c9716920b6": "Không gán vai trò",
    "audit_109e53ae6726": "Tạo dàn ý",
    "audit_82c2dbf0a17e": "Viết bài",
    "audit_9648d56af475": "Cải thiện bài viết",
    "audit_5c6805fbd29f": "Tạo hình ảnh",
    "audit_6507b676a08f": "Tạo / cập nhật bài viết",
    "audit_ce2e6e056f80": "Lọc bài viết",
    "audit_063670e1bf6e": "Đang lưu...",
    "audit_f150199efd1f": "Lưu Sơ Đồ Quy Trình",
    "audit_a2b1f3c58451": "Thu nhỏ",
    "audit_5d000cd701c3": "Thu nhỏ sơ đồ",
    "audit_cbfde1f64850": "Đặt lại 100%",
    "audit_affed1defc54": "Phóng to",
    "audit_24a3087ee36b": "Phóng to sơ đồ",
    "audit_7028dfd41751": "— Dùng Prompt Block chính —",
    "audit_5c31fd91e1a4": "— Chọn prompt từ vựng —",
    "audit_294dcf75549f": "0. Bóc tách theo Tag [START...END]",
    "audit_6ff7fd5ab1cc": "Lọc điều kiện tùy chỉnh",
    "audit_7cd3f724ca7f": "1. Bóc tách Dàn ý (Markdown -> JSON)",
    "audit_d158cfafd9f0": "2. Bóc tách Từ khóa (Markdown -> JSON)",
    "audit_9915fb6644fc": "3. Bóc tách FAQ",
    "audit_7a8c30135183": "4. Chấm điểm SEO (FAQ + Bảng)",
    "audit_64ab5110f629": "Chọn tag...",
    "audit_9cc08c0c5759": "Lưu nghiên cứu từ vựng (Topic Cluster)",
    "audit_b82a2e8527f3": "Đăng bình luận / review (WordPress)"
  }
};

function locale() {
  const value = typeof document !== 'undefined' ? document.documentElement?.lang : 'en';
  return String(value || 'en').toLowerCase().startsWith('vi') ? 'vi' : 'en';
}

export function auditT(key) {
  const current = locale();
  return messages[current]?.[key] ?? messages.en?.[key] ?? key;
}
