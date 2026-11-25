<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * User-facing AI error categories (presentation only — not routing policy).
 */
enum AiErrorPresentationCategory: string
{
    case ProviderRelay = 'provider_relay';
    case ProviderRateLimit = 'provider_rate_limit';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidCredential = 'invalid_credential';
    case ProviderQuota = 'provider_quota';
    case RoutesExhausted = 'routes_exhausted';
    case PromptInputValidation = 'prompt_input_validation';
    case OutputValidation = 'output_validation';
    case InternalFailure = 'internal_failure';
    case HttpRouteMismatch = 'http_route_mismatch';
    case Unknown = 'unknown';

    public function labelVi(): string
    {
        return match ($this) {
            self::ProviderRelay => 'Lỗi AI Provider (relay/gateway)',
            self::ProviderRateLimit => 'AI Provider giới hạn yêu cầu',
            self::ProviderUnavailable => 'AI Provider tạm thời không khả dụng',
            self::InvalidCredential => 'API key không hợp lệ',
            self::ProviderQuota => 'Hết quota hoặc credits',
            self::RoutesExhausted => 'Không còn tuyến AI khả dụng',
            self::PromptInputValidation => 'Dữ liệu đầu vào không hợp lệ',
            self::OutputValidation => 'AI trả kết quả không đúng định dạng',
            self::InternalFailure => 'Lỗi hệ thống SEO-OPS',
            self::HttpRouteMismatch => 'Lỗi API nội bộ SEO-OPS',
            self::Unknown => 'Chưa xác định nguyên nhân',
        };
    }
}
