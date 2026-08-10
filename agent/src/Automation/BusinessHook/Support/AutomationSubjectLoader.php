<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Load subject từ subject_type + subject_id only — không reconstruct từ payload.
 */
final class AutomationSubjectLoader
{
    /**
     * @return array{model: ?Model, error_code: ?string, error_message: ?string}
     */
    public function load(BusinessEvent $event, bool $required = false): array
    {
        if ($event->subject_type === null || $event->subject_id === null) {
            if ($required) {
                return [
                    'model' => null,
                    'error_code' => BusinessHookErrorCode::SubjectNotFound->value,
                    'error_message' => 'Business event has no subject_type/subject_id.',
                ];
            }

            return ['model' => null, 'error_code' => null, 'error_message' => null];
        }

        if (! class_exists($event->subject_type)) {
            return [
                'model' => null,
                'error_code' => BusinessHookErrorCode::SubjectNotFound->value,
                'error_message' => "Subject class [{$event->subject_type}] not found.",
            ];
        }

        /** @var class-string<Model> $class */
        $class = $event->subject_type;
        $query = $class::query();

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);
        if ($usesSoftDeletes) {
            /** @var Model|null $trashed */
            $trashed = $class::query()->withTrashed()->find($event->subject_id);
            if ($trashed === null) {
                return [
                    'model' => null,
                    'error_code' => BusinessHookErrorCode::SubjectNotFound->value,
                    'error_message' => "Subject [{$class}#{$event->subject_id}] not found.",
                ];
            }

            if (method_exists($trashed, 'trashed') && $trashed->trashed()) {
                return [
                    'model' => null,
                    'error_code' => BusinessHookErrorCode::SubjectDeleted->value,
                    'error_message' => "Subject [{$class}#{$event->subject_id}] is soft-deleted.",
                ];
            }

            return ['model' => $trashed, 'error_code' => null, 'error_message' => null];
        }

        $model = $query->find($event->subject_id);
        if ($model === null) {
            return [
                'model' => null,
                'error_code' => BusinessHookErrorCode::SubjectNotFound->value,
                'error_message' => "Subject [{$class}#{$event->subject_id}] not found.",
            ];
        }

        return ['model' => $model, 'error_code' => null, 'error_message' => null];
    }

    public function require(BusinessEvent $event): Model
    {
        $result = $this->load($event, true);
        if ($result['model'] instanceof Model) {
            return $result['model'];
        }

        throw new AutomationException(
            (string) ($result['error_code'] ?? BusinessHookErrorCode::SubjectNotFound->value),
            (string) ($result['error_message'] ?? 'Subject required.'),
        );
    }
}
