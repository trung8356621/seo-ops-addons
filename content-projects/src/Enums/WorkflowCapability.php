<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Capability Settings gắn Workflow — dùng để enforce role bắt buộc.
 */
enum WorkflowCapability: string
{
    case PublishArticle = 'publish_article';
    case ContentOnly = 'content_only';
    case Improve = 'improve';
    case CreateImage = 'create_image';
    case ProductGallery = 'product_gallery';
    case TypographyImage = 'typography_image';
    case CreateVideo = 'create_video';
    case PostReview = 'post_review';

    public function labelVi(): string
    {
        return match ($this) {
            self::PublishArticle => 'Đăng bài viết (Publish)',
            self::ContentOnly => 'Viết bài (content-only)',
            self::Improve => 'Cải thiện bài viết',
            self::CreateImage => 'Tạo ảnh bài viết',
            self::ProductGallery => 'Product Gallery',
            self::TypographyImage => 'Typography image',
            self::CreateVideo => 'Tạo video',
            self::PostReview => 'Post review',
        };
    }

    /**
     * @return list<WorkflowExecutionRole>
     */
    public function requiredRoles(): array
    {
        return match ($this) {
            self::PublishArticle => [
                WorkflowExecutionRole::ArticleOutlineGenerate,
                WorkflowExecutionRole::ArticleContentGenerate,
            ],
            self::ContentOnly => [
                WorkflowExecutionRole::ArticleContentGenerate,
            ],
            self::Improve => [
                WorkflowExecutionRole::ArticleContentImprove,
            ],
            // Image/gallery/video: không ép article.image.generate nếu contract khác —
            // chỉ báo soft khi thiếu role image (doctor), Settings không block cứng.
            self::CreateImage,
            self::ProductGallery,
            self::TypographyImage,
            self::CreateVideo,
            self::PostReview => [],
        };
    }
}
