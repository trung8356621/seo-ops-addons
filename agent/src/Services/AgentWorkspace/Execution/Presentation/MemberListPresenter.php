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
        foreach ($members as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['value'] ?? '');
            $email = (string) ($row['email'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if ($id === '' && isset($row['label'])) {
                $lines[] = (string) $row['label'];
                $lines[] = '';
                continue;
            }
            $lines[] = 'ID: '.$id;
            $lines[] = 'Email: '.($email !== '' ? $email : '—');
            $lines[] = 'Name: '.($name !== '' ? $name : '—');
            if (array_key_exists('available', $row)) {
                $lines[] = 'Available: '.((bool) $row['available'] ? 'yes' : 'no');
            }
            $lines[] = '';
        }

        return ReadResultPresenter::card($title, $lines);
    }
}
