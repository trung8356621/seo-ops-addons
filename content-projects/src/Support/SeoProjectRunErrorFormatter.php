<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

final class SeoProjectRunErrorFormatter
{
    /**
     * @param  ?array{title: string, prompt_name: string, message: string}  $failedStep
     * @return array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }
     */
    public function fromWorkflowFailure(string $detail, ?array $failedStep = null): array
    {
        return $this->build(
            detail: $detail,
            failedStep: $failedStep,
            publicMessage: __('seo-content-ai::filament.projects.run_error_public_workflow'),
        );
    }

    /**
     * @return array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }
     */
    public function fromThrowable(\Throwable $exception): array
    {
        $detail = trim($exception->getMessage());
        $class = $exception::class;

        if ($exception->getPrevious() instanceof \Throwable) {
            $detail .= "\n[previous] " . trim($exception->getPrevious()->getMessage());
        }

        return $this->build(
            detail: $detail !== '' ? $detail : $class,
            errorClass: $class,
            errorTrace: $exception->getTraceAsString(),
            publicMessage: __('seo-content-ai::filament.projects.run_error_public_generic'),
        );
    }

    /**
     * @return array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }
     */
    public function fromPlainDetail(string $detail, ?string $publicMessage = null): array
    {
        return $this->build(
            detail: $detail,
            publicMessage: $publicMessage ?? __('seo-content-ai::filament.projects.run_error_public_generic'),
        );
    }

    public function displayMessage(array $item): string
    {
        if (($item['status'] ?? '') !== 'failed') {
            return (string) ($item['message'] ?? '');
        }

        $detail = trim((string) ($item['error_detail'] ?? $item['message'] ?? ''));

        if ($this->isDebug() && $detail !== '') {
            return $detail;
        }

        return (string) ($item['message'] ?? __('seo-content-ai::filament.projects.run_error_public_generic'));
    }

    public function isDebug(): bool
    {
        return (bool) config('app.debug');
    }

    /**
     * @param  ?array{title: string, prompt_name: string, message: string}  $failedStep
     * @return array{
     *     message: string,
     *     error_detail: string,
     *     error_class: ?string,
     *     error_trace: ?string,
     *     failed_step: ?array{title: string, prompt_name: string, message: string}
     * }
     */
    private function build(
        string $detail,
        ?array $failedStep = null,
        ?string $errorClass = null,
        ?string $errorTrace = null,
        ?string $publicMessage = null,
    ): array {
        return [
            'message' => $this->isDebug() ? $detail : ($publicMessage ?? $detail),
            'error_detail' => $detail,
            'error_class' => $errorClass,
            'error_trace' => $errorTrace,
            'failed_step' => $failedStep,
        ];
    }
}
