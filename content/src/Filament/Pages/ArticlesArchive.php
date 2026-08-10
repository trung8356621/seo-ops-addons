<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Filament\Panel;

/**
 * Tombstone — trang Archive submenu đã bỏ.
 * File này chỉ để deploy đè bản cũ trên remote (FTP không xóa file orphan).
 * Sau khi remote đã overwrite + optimize:clear, xóa hẳn file này.
 */
final class ArticlesArchive extends SeoPanelPage
{
    protected static ?string $slug = 'articles/archive';

    protected static bool $shouldRegisterNavigation = false;

    public static function registerRoutes(Panel $panel): void
    {
        // Không đăng ký /articles/archive.
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }
}
