<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordGroupCatalog
{
    /**
     * @return list<array{key: string, label: string, phrases: list<string>}>
     */
    public static function systemDefaults(): array
    {
        return [
            ['key' => 'material', 'label' => 'Vật liệu', 'phrases' => ['canvas', 'vải bố', 'khong det', 'không dệt', 'polyester', 'pvc', 'da']],
            ['key' => 'care', 'label' => 'Bảo quản', 'phrases' => ['giặt', 'vệ sinh', 'bảo quản', 'làm sạch', 'xử lý vết']],
            ['key' => 'price', 'label' => 'Giá', 'phrases' => ['giá', 'báo giá', 'chi phí', 'bao nhiêu']],
            ['key' => 'location', 'label' => 'Địa phương', 'phrases' => ['tp.hcm', 'hồ chí minh', 'hà nội', 'đà nẵng', 'bình dương']],
            ['key' => 'audience', 'label' => 'Đối tượng', 'phrases' => ['học sinh', 'sinh viên', 'nam', 'nữ', 'trẻ em', 'doanh nghiệp']],
            ['key' => 'size', 'label' => 'Kích thước', 'phrases' => ['size', 'kích thước', 'lớn', 'nhỏ', 'mini']],
            ['key' => 'feature', 'label' => 'Tính năng', 'phrases' => ['chống nước', 'laptop', 'usb', 'ngăn']],
            ['key' => 'brand', 'label' => 'Thương hiệu', 'phrases' => ['thương hiệu', 'brand']],
            ['key' => 'use_case', 'label' => 'Use case', 'phrases' => ['du lịch', 'đi học', 'đi làm', 'quà tặng']],
            ['key' => 'technique', 'label' => 'Kỹ thuật', 'phrases' => ['may', 'cắt', 'in', 'thêu']],
        ];
    }
}
