<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

enum ArticleWritingSourceType: string
{
    case Outline = 'outline';
    case ExistingArticle = 'existing_article';
    case Brief = 'brief';

    public function labelVi(): string
    {
        return match ($this) {
            self::Outline => 'Dàn ý và hướng dẫn viết',
            self::ExistingArticle => 'Bài viết hiện có',
            self::Brief => 'Brief (tiêu đề / từ khóa / mô tả)',
        };
    }

    public function historyBadge(): string
    {
        return match ($this) {
            self::Outline => 'Outline',
            self::ExistingArticle => 'Existing article',
            self::Brief => 'Brief',
        };
    }

    public function sourceRule(): string
    {
        return match ($this) {
            self::Outline => 'Dàn ý và hướng dẫn viết là cấu trúc bắt buộc.',
            self::ExistingArticle => 'Viết lại toàn bộ bài hiện có, giữ thông tin đúng và hữu ích, '
                .'không paraphrase từng câu, không giữ cấu trúc yếu chỉ vì bài cũ có.',
            self::Brief => 'Tự xây cấu trúc phù hợp từ tiêu đề/từ khóa/mô tả.',
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return self::tryFrom($raw);
    }
}
