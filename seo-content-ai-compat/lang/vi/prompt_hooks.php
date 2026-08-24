<?php

declare(strict_types=1);

return [
    'none' => 'Không sử dụng Hook',
    'experimental_badge' => 'Thử nghiệm',
    'experimental_warning' => 'Hook đang ở phiên bản thử nghiệm :version.',
    'execution_failed_title' => 'Prompt Hook thất bại',
    'hook_template_owns_prompt' => 'Khi chọn Hook, template + output contract do Hook Definition quản lý. Nội dung Markdown bên phải chỉ dùng khi không gắn Hook.',
    'hook_legacy_prompt_template_note' => 'Hook quản lý contract input/output và runtime. Nội dung Prompt hiện tại vẫn là template gửi đến AI.',
    'input_mapping_hint' => 'Map biến workflow → input Hook (cùng tên {{field}} trừ khi ghi chú khác).',

    'variables' => [
        'keyword' => 'Từ khóa chính',
        'old_title' => 'Tiêu đề hiện tại',
        'title' => 'Tiêu đề bài viết',
        'old_description' => 'Meta description hiện tại',
        'focus_keyword' => 'Từ khóa trọng tâm',
        'outline' => 'Dàn ý bài viết',
        'section_content' => 'Nội dung phần hiện tại',
        'content_excerpt' => 'Nội dung tóm tắt',
        'language' => 'Ngôn ngữ đầu ra',
        'domain' => 'Tên miền',
        'post_title' => 'Tiêu đề bài viết',
        'post_excerpt' => 'Nội dung tóm tắt',
        'post_type' => 'Loại bài viết',
        'site_short_description' => 'Mô tả ngắn của website',
        'site_description' => 'Mô tả website',
        'tone' => 'Giọng điệu',
        'input' => 'Nội dung nguồn',
        'seed_topic' => 'Chủ đề gốc',
        'count' => 'Số lượng gợi ý',
        'brief' => 'Brief / mô tả ngắn',
    ],

    'article_title_suggestion' => [
        'label' => 'Gợi ý tiêu đề bài viết',
        'description' => 'Gợi ý tiêu đề mới cho bài viết dựa trên từ khóa và tiêu đề hiện tại.',
        'presentation' => [
            'default_instructions' => [
                'Chỉ trả về đúng một tiêu đề dạng plain text.',
                'Bám sát chủ đề và từ khóa chính.',
                'Nếu có tiêu đề cũ, hãy cải thiện — không sao chép nguyên xi.',
                'Giữ tiêu đề trong giới hạn độ dài đã cấu hình khi có thể.',
                'Không thêm giải thích, tiền tố, markdown hoặc dấu ngoặc bao quanh.',
            ],
            'output_format' => [
                'Một dòng tiêu đề hoàn chỉnh — không phải danh sách và không kèm bình luận.',
            ],
            'notes' => [
                'Thiết lập preserve-meaning và max-length trên Prompt Hook vẫn được áp dụng.',
            ],
        ],
        'template' => <<<'PROMPT'
## Hook constraints — article title suggestion
- Return exactly one article title as plain text.
- Do not add explanations, prefixes (e.g. "Title:" / "Tiêu đề:"), markdown, or quotes.
- Prefer including the focus keyword naturally: {{keyword}}
- If old_title is not null, treat it as context to improve — do not copy it blindly: {{old_title}}
- Keep the title within {{max_length}} characters when possible.
- preserve_meaning={{preserve_meaning}}
PROMPT,
        'settings' => [
            'max_length' => 'Độ dài tối đa',
            'preserve_meaning' => 'Giữ ý nghĩa tiêu đề hiện tại',
        ],
    ],

    'article_meta_description_suggestion' => [
        'label' => 'Gợi ý thẻ mô tả SEO',
        'description' => 'Gợi ý meta description phù hợp với nội dung và từ khóa của bài viết.',
        'presentation' => [
            'default_instructions' => [
                'Chỉ trả về đúng một đoạn meta description dạng plain text.',
                'Tóm tắt đúng nội dung dựa trên ngữ cảnh tiêu đề.',
                'Đưa từ khóa/chủ đề vào một cách tự nhiên — không nhồi nhét.',
                'Không bịa thông tin ngoài dữ liệu đầu vào.',
                'Nhắm độ dài giữa tối thiểu và tối đa đã cấu hình.',
            ],
            'output_format' => [
                'Một meta description hoàn chỉnh — không kèm tiền tố, danh sách hay giải thích.',
            ],
        ],
        'template' => <<<'PROMPT'
## Hook constraints — SEO meta description suggestion
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: {{title}}
- If old_description is not null, use it as context to improve: {{old_description}}
- Target length between {{min_length}} and {{max_length}} characters.
- Do not invent specific facts that are not present in the input.
PROMPT,
        'settings' => [
            'min_length' => 'Độ dài tối thiểu',
            'max_length' => 'Độ dài tối đa',
        ],
    ],

    'article_outline_structure_generate' => [
        'label' => 'Tạo cấu trúc dàn ý',
        'description' => 'Chỉ tạo outline markdown — provider call 1 trong flow split.',
        'presentation' => [
            'default_instructions' => [
                'Dàn ý có cấu trúc rõ ràng, khớp search intent.',
                'Không viết toàn bộ nội dung bài trong bước này.',
                'Không bao gồm vocabulary — chỉ outline.',
            ],
            'output_format' => [
                'Markdown outline only.',
            ],
        ],
    ],

    'article_vocabulary_generate' => [
        'label' => 'Tạo từ vựng bài viết',
        'description' => 'Chỉ tạo vocabulary / hướng dẫn viết — provider call 2 trong flow split.',
        'presentation' => [
            'default_instructions' => [
                'Tạo nhóm từ vựng ngữ nghĩa và hướng dẫn viết theo outline.',
                'Không viết lại dàn ý.',
            ],
            'output_format' => [
                'Markdown vocabulary / writing guidance only.',
            ],
        ],
    ],

    'article_outline_generate' => [
        'label' => 'Tạo dàn ý bài viết',
        'description' => 'Tạo dàn ý bài viết từ chủ đề, từ khóa và dữ liệu nguồn.',
        'presentation' => [
            'default_instructions' => [
                'Dàn ý có cấu trúc rõ ràng, khớp search intent.',
                'Các heading không trùng ý.',
                'Không viết toàn bộ nội dung bài trong bước outline.',
                'Kèm vocabulary / hướng dẫn viết ở phần thứ hai.',
            ],
            'output_format' => [
                'Markdown có cấu trúc với hai section đã khai báo:',
                'Task 1 — Outline giữa các marker outline.',
                'Task 2 — Vocabulary / hướng dẫn viết giữa các marker vocabulary.',
            ],
            'notes' => [
                'Nội dung Prompt Markdown là template gửi đến model cho Hook này.',
            ],
        ],
    ],

    'article_content_generate' => [
        'label' => 'Viết bài viết',
        'description' => 'Tạo bài đầy đủ từ outline, bài hiện có, hoặc brief (thử nghiệm 0.1.0). Gán ở Settings cho Editor + Stable Gate; Content Project Publish vẫn dùng Prompt trên Workflow node.',
        'presentation' => [
            'default_instructions' => [
                'Viết markdown bài đầy đủ từ outline, bài hiện có, hoặc brief (source_type).',
                'Khi source=outline phải tuân cấu trúc dàn ý; không bịa dữ kiện mâu thuẫn.',
                'Binding Settings dùng cho Editor viết lại toàn bộ (source=existing_article) và generate trực tiếp.',
            ],
            'output_format' => [
                'Markdown thân bài (không bọc fence).',
            ],
            'notes' => [
                'Hook key: article.content.generate — Publish Content Project vẫn lấy Prompt từ Workflow node.',
                'article.content.rewrite chỉ tương thích legacy; không gán Prompt mới vào hook đó.',
            ],
        ],
    ],

    'article_content_rewrite' => [
        'label' => 'Viết lại bài viết',
        'description' => 'Viết lại / cải thiện bài hiện có theo yêu cầu, giữ search intent và dữ kiện nguồn (thử nghiệm 0.1.0).',
    ],

    'article_faq_generate' => [
        'label' => 'Tạo FAQ bài viết',
        'description' => 'Tạo các câu hỏi thường gặp dựa trên nội dung bài viết.',
        'presentation' => [
            'default_instructions' => [
                'Câu hỏi phải liên quan trực tiếp đến bài viết.',
                'Câu trả lời ngắn gọn, hữu ích.',
                'Không thêm dữ kiện không có trong nguồn.',
                'Tránh tạo các câu hỏi trùng ý.',
            ],
            'output_format' => [
                'Một JSON object: {"faqs":[{"question":"...","answer":"..."}, ...]}',
                'Dạng dễ đọc mỗi mục: Câu hỏi / Câu trả lời.',
                'Không dùng markdown fence và không viết bài luận ngoài JSON object.',
            ],
            'notes' => [
                'Lưu FAQ vào bài viết thuộc về caller — Hook chỉ sinh nội dung.',
            ],
        ],
    ],

    'article_featured_snippet_generate' => [
        'label' => 'Tạo featured snippet',
        'description' => 'Tạo đoạn trả lời ngắn gọn có khả năng phù hợp với featured snippet.',
        'presentation' => [
            'default_instructions' => [
                'Trả lời trực tiếp câu hỏi hoặc chủ đề chính.',
                'Ưu tiên thông tin có trong section hiện tại và dàn ý.',
                'Viết cô đọng, rõ ràng.',
                'Không bọc kết quả trong toàn bộ bài viết.',
                'Không thêm lời dẫn hoặc kết luận thừa.',
            ],
            'output_format' => [
                'Một khối HTML sẵn sàng cho featured snippet (định nghĩa, bước, gạch đầu dòng, hoặc bảng so sánh).',
                'Chỉ nội dung text/HTML — không kèm khung bài viết bao ngoài.',
            ],
        ],
    ],

    'article_content_translate' => [
        'label' => 'Dịch bài viết',
        'description' => 'Dịch nội dung bài viết sang ngôn ngữ đích, giữ nghĩa và cấu trúc.',
        'presentation' => [
            'default_instructions' => [
                'Dịch nội dung bài nguồn sang ngôn ngữ đích.',
                'Không làm thay đổi ý nghĩa nguồn.',
                'Giữ cấu trúc Markdown và cấp heading khi có.',
                'Không thêm giải thích trước hoặc sau bản dịch.',
            ],
            'output_format' => [
                'Chỉ nội dung bài đã dịch — không kèm giải thích.',
            ],
            'notes' => [
                'Nội dung Prompt Markdown là template gửi đến model cho Hook này.',
            ],
        ],
    ],

    'article_comment_generate' => [
        'label' => 'Tạo bình luận bài viết',
        'description' => 'Tạo bình luận độc giả tự nhiên để seeding trên bài viết.',
        'presentation' => [
            'default_instructions' => [
                'Đóng vai độc giả thật, không phải tác giả.',
                'Bình luận mang tính đóng góp hoặc đặt câu hỏi mở rộng.',
                'Email phải khớp với tên người bình luận.',
                'Không thêm lời chào, tiêu đề hoặc giải thích ngoài các dòng bình luận.',
            ],
            'output_format' => [
                'Mỗi bình luận là một dòng.',
                'Mỗi dòng: Họ và tên | Email | Nội dung bình luận',
                'Chỉ các dòng comment — không thêm tiêu đề hoặc văn bản bao quanh.',
            ],
            'notes' => [
                'Prompt Markdown quyết định số lượng bình luận cần yêu cầu.',
                'Parser nhận tối đa 10 dòng hợp lệ và bỏ qua dòng không hợp lệ.',
            ],
        ],
    ],

    'article_featured_image_generate' => [
        'label' => 'Create news thumbnail',
        'description' => 'Tạo thumbnail bài viết từ tiêu đề hiện tại, dùng pipeline ảnh đại diện thông thường.',
        'presentation' => [
            'default_instructions' => [
                'Giữ Prompt ngắn; model, kích thước và nhà cung cấp lấy từ pipeline thumbnail bài viết.',
                'Dùng {{title}} cho tiêu đề bài viết hiện tại.',
                'Không tạo model ảnh hay bộ settings ảnh riêng cho Hook này.',
            ],
            'output_format' => [
                'Ảnh do image tool của Prompt đã chọn sinh ra theo pipeline featured/thumbnail hiện tại.',
            ],
            'notes' => [
                'Operator có thể sửa Markdown Prompt trong Prompt settings.',
                'Automation có thể chọn Prompt này ở bước article.image.generate / featured-image.',
                'Hook này không thêm card settings ảnh riêng.',
            ],
        ],
    ],

    'product_gallery_generate' => [
        'label' => 'Gallery sản phẩm',
        'description' => 'Tạo ảnh gallery sản phẩm theo Prompt image đã gắn.',
        'presentation' => [
            'default_instructions' => [
                'Tạo ảnh phù hợp album gallery sản phẩm.',
                'Ưu tiên bố cục rõ, dễ dùng trên trang product.',
                'Tuân theo Prompt Markdown và thiết lập image tool của Prompt đã chọn.',
            ],
            'output_format' => [
                'Ảnh do model / pipeline image của Prompt đã chọn sinh ra.',
            ],
            'notes' => [
                'Hook này chỉ phục vụ tạo ảnh gallery.',
                'Không thay thế hoặc ghi đè trường Gallery Description của Product.',
            ],
        ],
    ],

    'keyword_discovery_structured' => [
        'label' => 'Khám phá từ khóa (JSON)',
        'description' => 'Phân tích và đề xuất dữ liệu từ khóa theo cấu trúc phục vụ Content Project.',
        'presentation' => [
            'default_instructions' => [
                'Giữ kết quả đúng chủ đề theo seed topic và brief.',
                'Tuân theo contract JSON có cấu trúc — không viết bài luận tự do.',
                'Tránh từ khóa trùng lặp.',
                'Chỉ trả JSON — không dùng markdown fence.',
            ],
            'output_format' => [
                'Một JSON object chứa danh sách từ khóa có cấu trúc.',
                'Caller hiện đọc từng mục dạng chuỗi hoặc object có field keyword.',
            ],
            'notes' => [
                'Hook không ghi từ khóa vào database domain — caller chịu trách nhiệm lưu.',
                'Các field ngoài keyword chưa cố định trong Hook schema — giữ mô tả JSON object generic.',
            ],
        ],
    ],
];
