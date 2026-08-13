<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;

/**
 * Conversational flow state machine helpers (Phase1-7 UX contract).
 *
 * UI timeline is the only surface. No modal/drawer expectations here.
 */
final class ConversationalAgentFlowService
{
    public const STATE_IDLE = 'idle';
    public const STATE_RESOLVING = 'resolving';
    public const STATE_AWAITING_INPUT = 'awaiting_input';
    public const STATE_READY_FOR_PREVIEW = 'ready_for_preview';
    public const STATE_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const STATE_EXECUTING = 'executing';
    public const STATE_COMPLETED = 'completed';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_FAILED = 'failed';
    public const STATE_EXPIRED = 'expired';

    /**
     * @param  list<array{key?:string,label?:string,type?:string,required?:bool,options?:list<array{value?:string,label?:string}>}>  $formSchema
     * @param  array<string, mixed>  $collectedInputs
     * @return list<string>
     */
    public function computeMissingRequiredFields(array $formSchema, array $collectedInputs): array
    {
        $missing = [];

        foreach ($formSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $required = (bool) ($field['required'] ?? false);
            if (! $required) {
                continue;
            }

            $value = $collectedInputs[$key] ?? null;
            if ($value === null) {
                $missing[] = $key;
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                $missing[] = $key;
                continue;
            }
            if (is_array($value) && $value === []) {
                $missing[] = $key;
                continue;
            }
        }

        return $missing;
    }

    /**
     * @param  array{key?:string,label?:string,type?:string,options?:list<array{value?:string,label?:string}>}  $field
     */
    public function parseFieldValue(array $field, string $rawInput): array
    {
        $type = (string) ($field['type'] ?? 'string');
        $label = (string) ($field['label'] ?? ($field['key'] ?? ''));
        $input = trim($rawInput);

        if ($input === '') {
            return ['ok' => false, 'error' => "Thiếu giá trị: {$label}"];
        }

        return match ($type) {
            'string', 'reference' => ['ok' => true, 'value' => $input],

            'textarea' => ['ok' => true, 'value' => $rawInput],

            'integer' => $this->parseInteger($input),
            'boolean' => $this->parseBoolean($input),

            'enum' => $this->parseEnum($field, $input),
            'select' => $this->parseEnum($field, $input),

            'date' => $this->parseDate($input),
            'datetime' => $this->parseDateTime($input),
            'month' => $this->parseMonth($input),

            'member', 'user' => $this->parseMemberId($input),

            'array' => $this->parseArray($rawInput),

            default => ['ok' => true, 'value' => $input],
        };
    }

    /**
     * @return array{ok:true,value: 'yes'|'no'}|array{ok:false,error:string}
     */
    public function parseConfirmationAnswer(string $rawInput): array
    {
        $t = trim(mb_strtolower($rawInput));

        $yes = ['yes', 'y', 'đồng ý', 'dong y', 'xac nhan', 'xác nhận'];
        $no = ['no', 'n', 'không', 'khong', 'hủy', 'huỷ', 'cancel', 'từ chối', 'tu choi'];

        if (in_array($t, $yes, true)) {
            return ['ok' => true, 'value' => 'yes'];
        }
        if (in_array($t, $no, true)) {
            return ['ok' => true, 'value' => 'no'];
        }

        return ['ok' => false, 'error' => 'invalid'];
    }

    /**
     * @param  array{key?:string,label?:string,type?:string}  $field
     * @return array{content:string, quickReplies?:list<array{label:string,value:string}>}
     */
    public function buildFieldQuestion(array $field): array
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $type = (string) ($field['type'] ?? 'string');

        return match ($type) {
            'boolean' => [
                'content' => "Thiếu tham số: {$label}\nBật {$label}?",
                'quickReplies' => [
                    ['label' => 'Có', 'value' => '1'],
                    ['label' => 'Không', 'value' => '0'],
                ],
            ],
            'enum', 'select' => [
                'content' => "Thiếu tham số: {$label}\nChọn {$label}:",
                'quickReplies' => array_values(array_map(
                    static fn ($opt): array => [
                        'label' => (string) ($opt['label'] ?? ($opt['value'] ?? '')),
                        'value' => (string) ($opt['value'] ?? ''),
                    ],
                    is_array($field['options'] ?? null) ? $field['options'] : [],
                )),
            ],
            'month' => [
                'content' => "Thiếu tham số: {$label}\nNhập tháng, ví dụ 08/2026 hoặc “tháng sau”.",
            ],
            'date' => [
                'content' => "Thiếu tham số: {$label}\nNhập ngày, ví dụ 29/07/2026.",
            ],
            'datetime' => [
                'content' => "Thiếu tham số: {$label}\nNhập ngày giờ (YYYY-MM-DD HH:MM).",
            ],
            'integer' => [
                'content' => "Thiếu tham số: {$label}\nNhập {$label} bằng số.",
            ],
            'member', 'user' => [
                'content' => "Thiếu tham số: {$label}\nNhập member ID phụ trách (chỉ số ID).\nNếu chưa biết ID, dùng `/member-list`.",
            ],
            'array' => [
                'content' => "Thiếu tham số: {$label}\nNhập mỗi mục trên một dòng hoặc phân cách bằng dấu phẩy.",
            ],
            default => [
                'content' => "Thiếu tham số: {$label}\nNhập {$label}.",
            ],
        };
    }

    /**
     * @return array{ok:true,value:string|int|bool|array}|array{ok:false,error:string}
     */
    private function parseInteger(string $input): array
    {
        if (! preg_match('/^-?\d+$/', $input)) {
            return ['ok' => false, 'error' => 'invalid_integer'];
        }

        return ['ok' => true, 'value' => (int) $input];
    }

    /**
     * Channel-neutral member ID (plain text). Accepts numeric ID only.
     *
     * @return array{ok:true,value:string}|array{ok:false,error:string}
     */
    private function parseMemberId(string $input): array
    {
        $normalized = trim($input);
        if (preg_match('/^#?(\d+)$/', $normalized, $match) === 1) {
            return ['ok' => true, 'value' => (string) ((int) $match[1])];
        }

        return [
            'ok' => false,
            'error' => 'Member ID phải là số. Dùng `/member-list` để xem danh sách.',
        ];
    }

    /**
     * @return array{ok:true,value:'1'|'0'}|array{ok:false,error:string}
     */
    private function parseBoolean(string $input): array
    {
        $t = mb_strtolower($input);

        $yes = ['yes', 'y', '1', 'true', 'đồng ý', 'dong y', 'có', 'co'];
        $no = ['no', 'n', '0', 'false', 'không', 'khong', 'hủy', 'huỷ', 'cancel'];

        if (in_array($t, $yes, true)) {
            return ['ok' => true, 'value' => '1'];
        }
        if (in_array($t, $no, true)) {
            return ['ok' => true, 'value' => '0'];
        }

        return ['ok' => false, 'error' => 'invalid_boolean'];
    }

    /**
     * @param  array{options?:list<array{value?:string,label?:string}>}  $field
     * @return array{ok:true,value:string}|array{ok:false,error:string}
     */
    private function parseEnum(array $field, string $input): array
    {
        $opts = is_array($field['options'] ?? null) ? $field['options'] : [];
        $t = trim($input);

        foreach ($opts as $opt) {
            $val = (string) ($opt['value'] ?? '');
            $lbl = (string) ($opt['label'] ?? '');
            if ($val !== '' && mb_strtolower($val) === mb_strtolower($t)) {
                return ['ok' => true, 'value' => $val];
            }
            if ($lbl !== '' && mb_strtolower($lbl) === mb_strtolower($t)) {
                return ['ok' => true, 'value' => $val];
            }
        }

        return ['ok' => false, 'error' => 'invalid_enum'];
    }

    /**
     * Accept YYYY-MM-DD or DD/MM/YYYY
     * @return array{ok:true,value:string}|array{ok:false,error:string}
     */
    private function parseDate(string $input): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m) === 1) {
            return ['ok' => true, 'value' => $input];
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $input, $m) === 1) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            return ['ok' => true, 'value' => sprintf('%04d-%02d-%02d', $y, $mo, $d)];
        }

        return ['ok' => false, 'error' => 'invalid_date'];
    }

    /**
     * @return array{ok:true,value:string}|array{ok:false,error:string}
     */
    private function parseDateTime(string $input): array
    {
        // Allow "YYYY-MM-DD HH:MM"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/', $input, $m) === 1) {
            return ['ok' => true, 'value' => $m[1].'T'.$m[2]];
        }

        return ['ok' => false, 'error' => 'invalid_datetime'];
    }

    /**
     * Accept MM/YYYY, YYYY-MM, "tháng này", "tháng sau"
     * @return array{ok:true,value:string}|array{ok:false,error:string}
     */
    private function parseMonth(string $input): array
    {
        $t = mb_strtolower(trim($input));

        if (in_array($t, ['tháng sau', 'thang sau'], true)) {
            return ['ok' => true, 'value' => now()->addMonthNoOverflow()->format('Y-m')];
        }
        if (in_array($t, ['tháng này', 'thang nay', 'tháng hiện tại', 'thang hien tai'], true)) {
            return ['ok' => true, 'value' => now()->format('Y-m')];
        }

        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $t, $m) === 1) {
            $mo = (int) $m[1];
            $y = (int) $m[2];
            if ($mo < 1 || $mo > 12) {
                return ['ok' => false, 'error' => 'invalid_month'];
            }
            return ['ok' => true, 'value' => sprintf('%04d-%02d', $y, $mo)];
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $t, $m) === 1) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            if ($mo < 1 || $mo > 12) {
                return ['ok' => false, 'error' => 'invalid_month'];
            }
            return ['ok' => true, 'value' => sprintf('%04d-%02d', $y, $mo)];
        }

        return ['ok' => false, 'error' => 'invalid_month'];
    }

    /**
     * @return array{ok:true,value:list<string>}|array{ok:false,error:string}
     */
    private function parseArray(string $rawInput): array
    {
        $parts = preg_split('/\r\n|\r|\n|,/', $rawInput) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $v = trim((string) $p);
            if ($v === '') {
                continue;
            }
            $out[] = $v;
        }

        return ['ok' => true, 'value' => $out];
    }

    /**
     * @return array<string, array{key:string,label:string,type:string,options?:list<array{value:string,label:string}>}>
     */
    public function indexFormSchema(array $formSchema): array
    {
        $out = [];
        foreach ($formSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'key' => $key,
                'label' => (string) ($field['label'] ?? $key),
                'type' => (string) ($field['type'] ?? 'string'),
                'options' => is_array($field['options'] ?? null)
                    ? array_map(
                        static fn ($opt): array => [
                            'value' => (string) ($opt['value'] ?? ''),
                            'label' => (string) ($opt['label'] ?? ''),
                        ],
                        $field['options'],
                    )
                    : [],
            ];
        }

        return $out;
    }
}

