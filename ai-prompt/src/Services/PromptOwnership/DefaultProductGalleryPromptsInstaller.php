<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use App\Models\ApiConnection;

/**
 * Idempotent default Prompts + Settings bindings for Mode 2 Parent/Child hooks.
 * Never overwrites operator bindings or custom Prompt content.
 */
final class DefaultProductGalleryPromptsInstaller
{
    public const HOOK_PLAN = 'product.gallery.plan';

    public const HOOK_PARENT = 'product.gallery.parent.generate';

    public const HOOK_CHILD = 'product.gallery.child.generate';

    public const NAME_PLAN = 'Product Gallery Planner';

    public const NAME_PARENT = 'Product Gallery Parent Image';

    public const NAME_CHILD = 'Product Gallery Child Image';

    public const MARKDOWN_PLAN = <<<'MD'
Bạn là hệ thống lập kế hoạch album ảnh sản phẩm.

Nhiệm vụ:
Tạo danh sách {{ requested_image_count }} ảnh cần sinh cho sản phẩm.

Thông tin sản phẩm:

Tên:
{{ product_title }}

Từ khóa:
{{ keyword }}

Mô tả:
{{ product_description }}

Thuộc tính:
{{ product_attributes }}

Đặc điểm nhận diện:
{{ product_identity }}

Ràng buộc không được thay đổi:
{{ negative_constraints }}

Ngôn ngữ nhãn:
{{ language }}

Yêu cầu:
- Mỗi phần tử mô tả đúng một ảnh riêng biệt.
- Không tạo collage.
- Không tạo sprite sheet.
- Không tạo grid.
- Không yêu cầu chữ, nhãn, logo quảng cáo hoặc typography nằm trong ảnh.
- Các góc chụp phải khác nhau nhưng vẫn giữ nguyên cùng một sản phẩm.
- Ưu tiên ảnh hữu ích trong bài giới thiệu sản phẩm.
- Không tự thêm tính năng, phụ kiện hoặc chi tiết không có trong dữ liệu.
- Không yêu cầu thay đổi màu sắc, chất liệu, hình dáng hoặc cấu trúc nhận diện.
- `instruction` phải là chỉ dẫn tạo ảnh cụ thể, không phải nội dung marketing.
- Số lượng shots không vượt quá {{ requested_image_count }}.

Chỉ trả về JSON đúng contract sau:

{
  "shots": [
    {
      "slot": 1,
      "shot_key": "front",
      "label": "Mặt trước",
      "priority": "required",
      "aspect_ratio": "1:1",
      "instruction": "..."
    }
  ]
}

Không trả markdown.
Không trả giải thích.
Không thêm key ngoài contract.
MD;

    public const MARKDOWN_PARENT = <<<'MD'
Tạo đúng một ảnh tham chiếu chính cho sản phẩm sau.

Thông tin nhận diện sản phẩm:

Tên:
{{ product_title }}

Danh mục:
{{ product_category }}

Thương hiệu:
{{ product_brand }}

Màu chính:
{{ primary_color }}

Màu phụ:
{{ secondary_color }}

Chất liệu:
{{ material }}

Hình dáng:
{{ product_shape }}

Chi tiết nhận diện:
{{ distinctive_features }}

Ràng buộc không được thay đổi:
{{ negative_constraints }}

Yêu cầu ảnh:
- Chỉ một sản phẩm chính.
- Chỉ một khung hình.
- Không collage.
- Không grid.
- Không sprite sheet.
- Không chia nhiều panel.
- Không chữ.
- Không caption.
- Không nhãn quảng cáo.
- Không watermark.
- Không thêm phụ kiện không có trong dữ liệu hoặc ảnh tham chiếu.
- Giữ chính xác màu sắc, chất liệu, hình dáng và chi tiết nhận diện.
- Sản phẩm phải hiện rõ, dễ dùng làm ảnh tham chiếu cho các ảnh tiếp theo.
- Góc nhìn trung tính, thể hiện đầy đủ nhận dạng sản phẩm.
- Nền sạch, đơn giản, không gây nhiễu.
- Không tạo nhiều phiên bản sản phẩm trong cùng ảnh.
- Không tự thiết kế lại sản phẩm.

Kết quả phải là đúng một ảnh.

Reference image attachment:
- supplied at provider runtime via Gemini inlineData
- not embedded in this text prompt
MD;

    public const MARKDOWN_CHILD = <<<'MD'
Tạo đúng một ảnh mới của cùng sản phẩm trong ảnh tham chiếu.

Ảnh cha là nguồn nhận dạng chính của sản phẩm.

Thông tin sản phẩm:

Tên:
{{ product_title }}

Đặc điểm nhận diện:
{{ product_identity }}

Màu sắc:
{{ primary_color }}
{{ secondary_color }}

Chất liệu:
{{ material }}

Hình dáng:
{{ product_shape }}

Chi tiết đặc biệt:
{{ distinctive_features }}

Ràng buộc không được thay đổi:
{{ negative_constraints }}

Góc ảnh cần tạo:

Mã:
{{ shot_key }}

Nhãn:
{{ shot_label }}

Tỷ lệ:
{{ aspect_ratio }}

Chỉ dẫn:
{{ shot_instruction }}

Yêu cầu bắt buộc:
- Tạo đúng một ảnh.
- Chỉ thể hiện cùng một sản phẩm từ ảnh cha.
- Giữ nguyên nhận dạng sản phẩm.
- Giữ nguyên màu sắc.
- Giữ nguyên chất liệu.
- Giữ nguyên hình dáng.
- Giữ nguyên logo, khóa, quai, túi, đường may và các chi tiết đặc biệt.
- Thực hiện đúng góc ảnh đã chỉ định.
- Không tự thêm hoặc xóa chi tiết sản phẩm.
- Không thay đổi thiết kế sản phẩm.
- Không tạo sản phẩm khác.
- Không collage.
- Không grid.
- Không sprite sheet.
- Không nhiều panel.
- Không chữ.
- Không caption.
- Không watermark.
- Không typography.
- Không thêm vật thể che khuất sản phẩm.
- Không lặp nhiều bản sao của sản phẩm trừ khi shot instruction yêu cầu rõ và policy cho phép.

Ảnh cha quan trọng hơn mọi suy đoán từ mô tả chữ.

Kết quả phải là đúng một ảnh.

Reference image attachment:
- parent image supplied at provider runtime via Gemini inlineData
- not embedded in this text prompt
MD;

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{
     *     plan: array{prompt_id: int, created: bool, binding_set: bool},
     *     parent: array{prompt_id: int, created: bool, binding_set: bool},
     *     child: array{prompt_id: int, created: bool, binding_set: bool}
     * }
     */
    public function install(): array
    {
        return [
            'plan' => $this->installOne(
                self::HOOK_PLAN,
                self::NAME_PLAN,
                self::MARKDOWN_PLAN,
                'default',
                [
                    ['name' => 'product_title', 'description' => 'Product title'],
                    ['name' => 'keyword', 'description' => 'Focus keyword'],
                    ['name' => 'product_description', 'description' => 'Product description'],
                    ['name' => 'product_attributes', 'description' => 'Product attributes'],
                    ['name' => 'product_identity', 'description' => 'Identity features'],
                    ['name' => 'negative_constraints', 'description' => 'Negative constraints'],
                    ['name' => 'requested_image_count', 'description' => 'Requested shot count'],
                    ['name' => 'language', 'description' => 'Label language'],
                ],
            ),
            'parent' => $this->installOne(
                self::HOOK_PARENT,
                self::NAME_PARENT,
                self::MARKDOWN_PARENT,
                'image',
                [
                    ['name' => 'product_title', 'description' => 'Product title'],
                    ['name' => 'product_category', 'description' => 'Category'],
                    ['name' => 'product_brand', 'description' => 'Brand'],
                    ['name' => 'primary_color', 'description' => 'Primary color'],
                    ['name' => 'secondary_color', 'description' => 'Secondary color'],
                    ['name' => 'material', 'description' => 'Material'],
                    ['name' => 'product_shape', 'description' => 'Shape'],
                    ['name' => 'distinctive_features', 'description' => 'Distinctive features'],
                    ['name' => 'negative_constraints', 'description' => 'Negative constraints'],
                ],
            ),
            'child' => $this->installOne(
                self::HOOK_CHILD,
                self::NAME_CHILD,
                self::MARKDOWN_CHILD,
                'image',
                [
                    ['name' => 'product_title', 'description' => 'Product title'],
                    ['name' => 'product_identity', 'description' => 'Identity'],
                    ['name' => 'primary_color', 'description' => 'Primary color'],
                    ['name' => 'secondary_color', 'description' => 'Secondary color'],
                    ['name' => 'material', 'description' => 'Material'],
                    ['name' => 'product_shape', 'description' => 'Shape'],
                    ['name' => 'distinctive_features', 'description' => 'Distinctive features'],
                    ['name' => 'negative_constraints', 'description' => 'Negative constraints'],
                    ['name' => 'shot_key', 'description' => 'Shot key'],
                    ['name' => 'shot_label', 'description' => 'Shot label'],
                    ['name' => 'aspect_ratio', 'description' => 'Aspect ratio'],
                    ['name' => 'shot_instruction', 'description' => 'Shot instruction'],
                    ['name' => 'parent_media_id', 'description' => 'Parent media id (debug)'],
                ],
            ),
        ];
    }

    /**
     * @param  list<array{name: string, description: string}>  $variables
     * @return array{prompt_id: int, created: bool, binding_set: bool}
     */
    private function installOne(
        string $hookKey,
        string $name,
        string $markdown,
        string $tools,
        array $variables,
    ): array {
        $existing = SeoPrompt::query()
            ->where('hook_key', $hookKey)
            ->where('name', $name)
            ->orderBy('id')
            ->first();

        $created = false;
        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => $name,
                'title' => $name,
                'markdown_content' => $markdown,
                'hook_key' => $hookKey,
                'hook_version' => '0.1.0',
                'variables' => $variables,
                'ai_connection_id' => $this->defaultAiConnectionId(),
                'tools' => $tools,
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                    'gallery_mode' => 'parent_child',
                ],
            ]);
            $existing->save();
            $created = true;
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[$hookKey])) {
            $this->settings->savePromptHookBindings([
                $hookKey => $promptId,
            ]);
            $bindingSet = true;
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
        ];
    }

    private function defaultAiConnectionId(): ?int
    {
        try {
            $id = ApiConnection::query()->orderBy('id')->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function systemUserId(): int
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $authId > 0) {
            return (int) $authId;
        }

        try {
            $id = \App\Models\User::query()->orderBy('id')->value('id');
            if ($id !== null && (int) $id > 0) {
                return (int) $id;
            }
        } catch (\Throwable) {
        }

        return 1;
    }
}
