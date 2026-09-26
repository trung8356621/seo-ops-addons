<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi;

/**
 * Per-item outcome for Service API Draft intake.
 */
final class ServiceApiDraftIntakeItemResult
{
    public const STATUS_ADDED = 'added';

    public const STATUS_ALREADY_IN_DRAFT = 'already_in_draft';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly int $inputIndex,
        public readonly string $status,
        public readonly ?string $itemRef = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'input_index' => $this->inputIndex,
            'status' => $this->status,
        ];
        if ($this->itemRef !== null && $this->itemRef !== '') {
            $row['item_ref'] = $this->itemRef;
        }
        if ($this->message !== null && $this->message !== '') {
            $row['message'] = $this->message;
        }

        return $row;
    }
}
