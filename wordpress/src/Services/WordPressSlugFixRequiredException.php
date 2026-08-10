<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use RuntimeException;

final class WordPressSlugFixRequiredException extends RuntimeException
{
    public const ERROR_CODE = 'wordpress_slug_fix_required';

    public const MESSAGE = 'Chưa thể đồng bộ sang WordPress. Hãy chạy và hoàn tất “Fix slug all” trước để tránh tạo ảnh với slug không hợp lệ.';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly array $context = [],
    ) {
        parent::__construct(self::MESSAGE);
    }
}
