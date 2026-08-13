<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class MemberListPresenter
{
    /**
     * @param  list<array<string, mixed>>  $members
     */
    public function present(array $members, bool $availableOnly = false): array
    {
        $title = $availableOnly ? 'Thành viên sẵn sàng' : 'Danh sách thành viên';
        if ($members === []) {
            return ReadResultPresenter::card($title, [$title, 'Chưa có thành viên.']);
        }

        $lines = [$title, ''];
        $index = 1;
        foreach ($members as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['value'] ?? '');
            $email = (string) ($row['email'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if ($id === '' && isset($row['label'])) {
                $lines[] = $index.'. '.(string) $row['label'];
                $lines[] = '';
                $index++;
                continue;
            }
            $label = $name !== '' ? $name : ($email !== '' ? $email : '—');
            $lines[] = $index.'. '.$label.' — ID: '.$id;
            if ($email !== '' && $name !== '') {
                $lines[] = '   Email: '.$email;
            }
            if (array_key_exists('available', $row) && $row['available'] !== null) {
                $lines[] = '   Available: '.((bool) $row['available'] ? 'yes' : 'no');
            }
            $lines[] = '';
            $index++;
        }

        $lines[] = 'Dùng ID số với `/create-project` (ví dụ: 12).';

        return ReadResultPresenter::card($title, $lines);
    }
}
