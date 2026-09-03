<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Central Help registry (2-level groups → topics) for Global Help modal.
 * Served to Alpine via Blade JSON — no Vite dependency at runtime.
 */
final class SeoHelpRegistry
{
    /**
     * @return array{groups: list<array<string, mixed>>, contexts: array<string, array<string, mixed>>}
     */
    public static function clientPayload(): array
    {
        return [
            'groups' => self::groups(),
            'contexts' => self::contexts(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'id' => 'overview',
                'title' => 'Tổng quan',
                'modalTitle' => 'Hướng dẫn hệ thống',
                'topics' => [
                    [
                        'id' => 'system-overview',
                        'title' => 'Giới thiệu SEO Content AI',
                        'summary' => 'Panel SEO quản lý bài viết, media, keyword, sync WordPress và cấu hình AI.',
                        'steps' => [
                            'Dùng sidebar để chuyển module (Articles, Media, Keywords, Settings…).',
                            'Thanh trên cùng: chọn Domain / Content Project / View as (nếu có quyền).',
                            'Nút Help (?) luôn mở hướng dẫn theo trang hiện tại.',
                        ],
                    ],
                    [
                        'id' => 'navigation',
                        'title' => 'Điều hướng và phạm vi Domain',
                        'summary' => 'Mọi danh sách bài / media thường theo Domain đang chọn trên Global Bar.',
                        'steps' => [
                            'Chọn Domain cụ thể khi làm việc một site.',
                            'Chọn All domains (nếu được phép) để xem tổng quan đa site.',
                            'Content Project lọc bài theo dự án nội dung.',
                        ],
                    ],
                    [
                        'id' => 'help-usage',
                        'title' => 'Cách dùng Help',
                        'summary' => 'Help là tính năng toàn cục: một modal, nội dung đổi theo trang.',
                        'steps' => [
                            'Bấm Help trên header để mở hướng dẫn.',
                            'Cột trái: nhóm chức năng. Cột phải: chủ đề chi tiết.',
                            'Ô tìm kiếm lọc theo tên nhóm, topic và nội dung.',
                            'ESC hoặc click nền tối để đóng.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'dashboard',
                'title' => 'Dashboard',
                'modalTitle' => 'Hướng dẫn Dashboard',
                'topics' => [
                    [
                        'id' => 'dashboard-overview',
                        'title' => 'Tổng quan Dashboard',
                        'summary' => 'Theo dõi sức khỏe domain, điểm SEO và trạng thái sync.',
                        'steps' => [
                            'Xem widget thống kê bài viết / SEO score.',
                            'Kiểm tra bảng trạng thái sync WordPress nếu có.',
                            'Chuyển Domain trên Global Bar để đổi phạm vi dữ liệu.',
                        ],
                    ],
                    [
                        'id' => 'dashboard-widgets',
                        'title' => 'Widgets và All Domains',
                        'summary' => 'Khi All domains: xem health, tiến độ content project và team.',
                        'steps' => [
                            'All Domains List: health từng site.',
                            'Projects / Team widgets: tiến độ và năng suất.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'articles',
                'title' => 'Quản lý bài viết',
                'modalTitle' => 'Hướng dẫn quản lý bài viết',
                'topics' => [
                    [
                        'id' => 'articles-list',
                        'title' => 'Danh sách bài viết',
                        'summary' => 'Tab Posts: lọc, tìm, mở editor, gán project và đồng bộ.',
                        'steps' => [
                            'Dùng filter / search để tìm bài.',
                            'Click tiêu đề hoặc Edit để mở Article Editor.',
                            'Bulk actions: sync, gán project, đổi trạng thái (theo quyền).',
                        ],
                    ],
                    [
                        'id' => 'articles-tabs',
                        'title' => 'Các tab (Posts / Categories / Queue / Reviewed)',
                        'summary' => 'List Articles có nhiều tab nghiệp vụ.',
                        'steps' => [
                            'Posts: full WP inventory (gồm cả skipped / reviewed / archived).',
                            'Categories: quản lý danh mục WordPress đã sync.',
                            'Queue: theo dõi hàng đợi đồng bộ.',
                            'Reviewed / Skipped: hàng đợi làm việc riêng — không cắt denominator tab Posts.',
                        ],
                    ],
                    [
                        'id' => 'articles-create',
                        'title' => 'Tạo bài và Content Project',
                        'summary' => 'Tạo bài mới hoặc sinh từ task / project workflow.',
                        'steps' => [
                            'Create article từ nút trên list (nếu có quyền).',
                            'Gán Content Project để theo dõi tiến độ.',
                            'Sau khi tạo, mở Editor để chỉnh nội dung và SEO.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'article-editor',
                'title' => 'Article Editor',
                'modalTitle' => 'Hướng dẫn Article Editor',
                'topics' => [
                    [
                        'id' => 'overview',
                        'title' => 'Tổng quan giao diện',
                        'summary' => 'Sticky action header, dàn ý, Google Preview và module SEO/Links/FAQ.',
                        'steps' => [
                            'Sticky header: Save, Sync WP, Preview, Approve, More.',
                            'Cột trái: Google Preview + Outline.',
                            'Cột phải: SEO Assistant và Publishing.',
                            'Nút Help trên header hệ thống mở modal hướng dẫn này.',
                        ],
                    ],
                    [
                        'id' => 'save-draft',
                        'title' => 'Lưu bài và bản nháp local',
                        'summary' => 'Save ghi server; gõ nội dung tạo draft local trong trình duyệt.',
                        'steps' => [
                            'Bấm Save article hoặc Ctrl+S để lưu bài.',
                            'Trạng thái Saving / Draft saved locally / Conflict hiện trên sticky header.',
                            'Draft local khôi phục khi mở lại nếu khác bản server.',
                            'Conflict 409: giữ draft, không reload tự động.',
                        ],
                    ],
                    [
                        'id' => 'wordpress-sync',
                        'title' => 'Đồng bộ WordPress',
                        'summary' => 'Sync WP đẩy nội dung sang WordPress qua automation queue.',
                        'steps' => [
                            'Bấm Sync WP hoặc Ctrl+Shift+S.',
                            'Chờ overlay hoàn tất — không đóng tab khi đang sync.',
                            'Restore từ More menu để kéo bản WP về (ghi đè local).',
                        ],
                    ],
                    [
                        'id' => 'outline',
                        'title' => 'Outline / Dàn ý',
                        'summary' => 'Quản lý heading H2–H4, nhảy tới section, thêm/đổi thứ tự.',
                        'steps' => [
                            'Thêm section từ Outline.',
                            'Đổi thứ tự heading bằng kéo hoặc nút move.',
                            'Bấm heading để cuộn tới nội dung tương ứng.',
                        ],
                        'target' => ['type' => 'widget', 'id' => 'outline'],
                    ],
                    [
                        'id' => 'google-preview',
                        'title' => 'Google Preview',
                        'summary' => 'Xem trước title/meta description dạng SERP và chỉnh SEO fields.',
                        'steps' => [
                            'Chỉnh SEO title / meta description trên preview.',
                            'Theo dõi độ dài và keyword highlight.',
                            'Lưu SEO fields trước khi Sync WP.',
                        ],
                        'target' => ['type' => 'scroll', 'id' => 'google-preview'],
                    ],
                    [
                        'id' => 'seo-assistant',
                        'title' => 'SEO Assistant',
                        'summary' => 'Phân tích SEO, gợi ý sửa, mở module liên quan.',
                        'steps' => [
                            'Mở tab SEO trên sidebar phải.',
                            'Chạy Analyze sau khi nội dung thay đổi.',
                            'Bấm violation để nhảy tới vùng cần sửa.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'seo'],
                    ],
                    [
                        'id' => 'featured-image',
                        'title' => 'Featured Image',
                        'summary' => 'Chọn / tạo ảnh đại diện cho bài viết.',
                        'steps' => [
                            'Mở module Images.',
                            'Chọn ảnh từ media library hoặc generate.',
                            'Kiểm tra alt text trước khi Sync.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'images'],
                    ],
                    [
                        'id' => 'images',
                        'title' => 'Images / Album',
                        'summary' => 'Ảnh trong bài, album sản phẩm, fix slug hàng loạt.',
                        'steps' => [
                            'Mở Images để xem danh sách ảnh trong bài.',
                            'Dùng Quick fix slug khi cần chuẩn hóa URL.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'images'],
                    ],
                    [
                        'id' => 'reviews',
                        'title' => 'Reviews',
                        'summary' => 'Đánh giá sản phẩm / virtual reviews gắn với bài.',
                        'steps' => [
                            'Mở module Reviews.',
                            'Generate hoặc refresh reviews khi cần.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'reviews'],
                    ],
                    [
                        'id' => 'links',
                        'title' => 'Links',
                        'summary' => 'Internal / external links và gợi ý liên kết nội bộ.',
                        'steps' => [
                            'Mở module Links.',
                            'Thêm internal link từ gợi ý hoặc search.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'links'],
                    ],
                    [
                        'id' => 'faq',
                        'title' => 'FAQ',
                        'summary' => 'Câu hỏi FAQ gắn shortcode [omi_faq].',
                        'steps' => [
                            'Mở module FAQ.',
                            'Thêm / sửa câu hỏi và câu trả lời.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'faq'],
                    ],
                    [
                        'id' => 'cta',
                        'title' => 'CTA',
                        'summary' => 'Call-to-action blocks trong bài.',
                        'steps' => [
                            'Mở module CTA.',
                            'Chọn hoặc chỉnh CTA phù hợp nội dung.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'cta'],
                    ],
                    [
                        'id' => 'publishing',
                        'title' => 'Publishing',
                        'summary' => 'Trạng thái, lịch đăng, categories WordPress.',
                        'steps' => [
                            'Mở Publishing trên sidebar.',
                            'Chọn status / schedule / categories.',
                            'Save rồi Sync WP để áp dụng lên WordPress.',
                        ],
                        'target' => ['type' => 'module', 'id' => 'publishing'],
                    ],
                    [
                        'id' => 'troubleshooting',
                        'title' => 'Xử lý lỗi thường gặp',
                        'summary' => 'Conflict save, sync treo, SEO stale, draft local.',
                        'steps' => [
                            'Save conflict: giữ draft, so sánh rồi lưu lại.',
                            'Sync overlay quá lâu: kiểm tra queue / Retry.',
                            'SEO stale: bấm Analyze lại.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'sync-queue',
                'title' => 'Đồng bộ WordPress',
                'modalTitle' => 'Hướng dẫn đồng bộ WordPress',
                'topics' => [
                    [
                        'id' => 'queue-overview',
                        'title' => 'Hàng đợi sync',
                        'summary' => 'Theo dõi job đẩy bài sang WordPress.',
                        'steps' => [
                            'Mở tab Queue trên Articles.',
                            'Xem trạng thái: queued / processing / failed / done.',
                            'Resync hoặc Cancel theo quyền và trạng thái job.',
                        ],
                    ],
                    [
                        'id' => 'queue-retry',
                        'title' => 'Retry và lỗi thường gặp',
                        'summary' => 'Khi sync fail: đọc message, sửa cấu hình domain/plugin, rồi resync.',
                        'steps' => [
                            'Kiểm tra plugin OMI SEO AI Bridge trên WordPress.',
                            'Xác nhận Domain đã kết nối token đúng.',
                            'Resync bài lỗi sau khi sửa nguyên nhân.',
                        ],
                    ],
                    [
                        'id' => 'queue-from-editor',
                        'title' => 'Sync từ Article Editor',
                        'summary' => 'Nút Sync WP trên editor cũng đẩy vào cùng pipeline queue.',
                        'steps' => [
                            'Save trước khi Sync nếu vừa sửa nội dung.',
                            'Không đóng tab khi overlay sync đang chạy.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'categories',
                'title' => 'Danh mục',
                'modalTitle' => 'Hướng dẫn danh mục',
                'topics' => [
                    [
                        'id' => 'categories-overview',
                        'title' => 'Quản lý Categories',
                        'summary' => 'Danh mục WordPress đã sync về panel SEO.',
                        'steps' => [
                            'Mở tab Categories trên Articles.',
                            'Đối chiếu với categories trên WordPress sau khi sync domain.',
                            'Gán category khi Publishing trong Article Editor.',
                        ],
                    ],
                    [
                        'id' => 'categories-sync',
                        'title' => 'Đồng bộ danh mục từ Domain',
                        'summary' => 'Categories lấy từ kết nối Domain / WordPress.',
                        'steps' => [
                            'Vào Domain settings và chạy sync nếu list trống.',
                            'Quay lại Categories để kiểm tra dữ liệu mới.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'media',
                'title' => 'Media',
                'modalTitle' => 'Hướng dẫn Media',
                'topics' => [
                    [
                        'id' => 'media-library',
                        'title' => 'Media Library',
                        'summary' => 'Thư viện ảnh/workspace dùng chung cho editor và AI tools.',
                        'steps' => [
                            'Upload hoặc import URL vào library.',
                            'Dùng picker trong Article Editor / Global AI Chat.',
                        ],
                    ],
                    [
                        'id' => 'media-editor',
                        'title' => 'Image tools',
                        'summary' => 'Chỉnh ảnh, watermark, tối ưu trước khi gắn vào bài.',
                        'steps' => [
                            'Mở Image Editor / Watermark từ menu Media.',
                            'Lưu bản đã xử lý rồi chọn lại trong picker.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'seo',
                'title' => 'SEO & Keywords',
                'modalTitle' => 'Hướng dẫn SEO',
                'topics' => [
                    [
                        'id' => 'keywords',
                        'title' => 'Keywords workspace',
                        'summary' => 'Quản lý từ khóa focus, topic và discovery.',
                        'steps' => [
                            'Mở Keywords trên sidebar.',
                            'Gắn focus keyword cho bài trong Editor hoặc list.',
                        ],
                    ],
                    [
                        'id' => 'articles-optimal',
                        'title' => 'Articles Optimal / Reviewed',
                        'summary' => 'Theo dõi bài đã audit SEO và cần tối ưu.',
                        'steps' => [
                            'Mở Articles Optimal hoặc tab Reviewed.',
                            'Sửa trong Article Editor rồi Analyze lại.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'settings',
                'title' => 'Cấu hình hệ thống',
                'modalTitle' => 'Hướng dẫn cấu hình',
                'topics' => [
                    [
                        'id' => 'settings-overview',
                        'title' => 'Tổng quan Settings',
                        'summary' => 'Prompt, workflow, AI connection, scoring, API.',
                        'steps' => [
                            'Settings Overview: điểm vào các mục cấu hình.',
                            'AI / API: kết nối model, GSC, SERP providers.',
                        ],
                    ],
                    [
                        'id' => 'settings-domain',
                        'title' => 'Domain & WordPress bridge',
                        'summary' => 'Mỗi Domain cần URL WP, token plugin và quyền sync.',
                        'steps' => [
                            'Mở Domain list / edit domain.',
                            'Cài / cập nhật plugin OMI SEO AI Bridge.',
                            'Test kết nối trước khi sync hàng loạt.',
                        ],
                    ],
                    [
                        'id' => 'settings-team',
                        'title' => 'Team & quyền',
                        'summary' => 'Phân quyền Content Manager / Editor / Admin mô phỏng.',
                        'steps' => [
                            'Dùng View as trên Global Bar để xem UI theo role.',
                            'Content Manager bị hạn chế sync / một số settings.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function contexts(): array
    {
        return [
            'dashboard' => [
                'id' => 'dashboard',
                'modalTitle' => 'Hướng dẫn Dashboard',
                'defaultGroupId' => 'dashboard',
                'routeNames' => ['filament.seo.pages.dashboard'],
                'pathPatterns' => ['\\/seo(?:\\/[a-zA-Z0-9]{32,64})?\\/?$'],
                'groupIds' => ['dashboard', 'overview', 'articles', 'settings'],
            ],
            'articles' => [
                'id' => 'articles',
                'modalTitle' => 'Hướng dẫn quản lý bài viết',
                'defaultGroupId' => 'articles',
                'routeNames' => [
                    'filament.seo.resources.articles.index',
                    'filament.seo.resources.articles.create',
                    'filament.seo.resources.articles.view',
                ],
                'pathPatterns' => ['\\/articles(?:\\/)?$', '\\/articles\\?'],
                'groupIds' => ['articles', 'sync-queue', 'categories', 'article-editor', 'overview'],
            ],
            'articleEditor' => [
                'id' => 'articleEditor',
                'modalTitle' => 'Hướng dẫn Article Editor',
                'defaultGroupId' => 'article-editor',
                'routeNames' => ['filament.seo.resources.articles.edit'],
                'pathPatterns' => ['\\/articles\\/\\d+\\/edit'],
                'groupIds' => ['article-editor', 'sync-queue', 'articles', 'media', 'overview'],
            ],
            'syncQueue' => [
                'id' => 'syncQueue',
                'modalTitle' => 'Hướng dẫn đồng bộ WordPress',
                'defaultGroupId' => 'sync-queue',
                'routeNames' => ['filament.seo.resources.articles.index'],
                'pathPatterns' => ['[?&]tab=queue\\b', '\\/articles\\/sync-queue'],
                'groupIds' => ['sync-queue', 'articles', 'article-editor', 'overview'],
            ],
            'categories' => [
                'id' => 'categories',
                'modalTitle' => 'Hướng dẫn danh mục',
                'defaultGroupId' => 'categories',
                'routeNames' => ['filament.seo.resources.articles.index'],
                'pathPatterns' => ['[?&]tab=categories\\b'],
                'groupIds' => ['categories', 'articles', 'overview'],
            ],
            'media' => [
                'id' => 'media',
                'modalTitle' => 'Hướng dẫn Media',
                'defaultGroupId' => 'media',
                'routeNames' => [
                    'filament.seo.pages.media-library',
                    'filament.seo.pages.media-image-editor',
                    'filament.seo.pages.watermark-editor',
                ],
                'pathPatterns' => ['\\/media(?:\\/|$)', 'watermark', 'image-processing'],
                'groupIds' => ['media', 'article-editor', 'overview'],
            ],
            'seo' => [
                'id' => 'seo',
                'modalTitle' => 'Hướng dẫn SEO',
                'defaultGroupId' => 'seo',
                'routeNames' => [
                    'filament.seo.resources.keywords.*',
                    'filament.seo.pages.performance-hub',
                    'filament.seo.pages.articles-optimal',
                ],
                'pathPatterns' => ['\\/keywords', 'performance-hub', 'articles-optimal'],
                'groupIds' => ['seo', 'articles', 'article-editor', 'overview'],
            ],
            'settings' => [
                'id' => 'settings',
                'modalTitle' => 'Hướng dẫn cấu hình',
                'defaultGroupId' => 'settings',
                'routeNames' => [
                    'filament.seo.pages.seo-settings*',
                    'filament.seo.resources.settings.*',
                    'filament.seo.resources.domains.*',
                    'filament.seo.resources.prompts.*',
                ],
                'pathPatterns' => ['\\/settings', '\\/domains', '\\/prompts', '\\/automation'],
                'groupIds' => ['settings', 'overview', 'sync-queue'],
            ],
            'system' => [
                'id' => 'system',
                'modalTitle' => 'Hướng dẫn hệ thống',
                'defaultGroupId' => 'overview',
                'routeNames' => [],
                'pathPatterns' => [],
                'groupIds' => ['overview', 'dashboard', 'articles', 'article-editor', 'sync-queue', 'media', 'seo', 'settings'],
            ],
        ];
    }
}
