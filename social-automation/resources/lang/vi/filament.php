<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'nav' => 'Dashboard',
        'title' => 'Social Automation',
        'intro' => 'Control plane trung tâm cho RPA Social Automation. Phạm vi global — không phụ thuộc website đang chọn.',
        'empty_schema' => 'Chưa migrate bảng registry hoặc chưa có Runner báo cáo. Số liệu giữ 0 đến khi migrate + enroll Runner.',
    ],
    'service' => [
        'nav' => 'Trạng thái Service',
        'title' => 'Trạng thái Service',
        'db_note' => 'DB plane riêng omi_social_automation. OPS-SERVER cấp entitlement và (tuỳ chọn) credential DB.',
    ],
    'packages' => [
        'nav' => 'Packages',
        'title' => 'Package Registry',
        'intro' => 'Release Widget/Workflow bất biến do RPA Admin publish qua Service API.',
        'empty' => 'Chưa có package.',
    ],
    'agents' => [
        'nav' => 'Agents',
        'title' => 'Agents',
        'intro' => 'Máy Runner. Online/offline do server tính từ heartbeat.',
        'empty' => 'Chưa có agent. Cấp Runner credential rồi gọi register/heartbeat.',
    ],
    'accounts' => [
        'nav' => 'Accounts',
        'title' => 'Accounts',
        'intro' => 'Định danh tài khoản nền tảng. Email/username khai báo không phải identity đã xác minh.',
        'empty' => 'Chưa có metadata account. Đồng bộ từ Runner.',
    ],
    'sessions' => [
        'nav' => 'Browser Sessions',
        'title' => 'Browser Sessions',
        'intro' => 'Ready = người dùng xác nhận sẵn sàng — không đồng nghĩa đã đăng nhập social.',
        'empty' => 'Chưa có browser session.',
    ],
    'tasks' => [
        'nav' => 'Tasks',
        'title' => 'Tasks',
        'intro' => 'Công việc tham chiếu package Widget/Workflow đã pin version.',
        'empty' => 'Chưa có task. Tạo qua Admin Service API (social-automation:admin:task).',
    ],
    'schedules' => [
        'nav' => 'Schedules',
        'title' => 'Schedules',
        'intro' => 'Cron trigger. Máy offline bị skip — không tạo backlog bù.',
        'empty' => 'Chưa có lịch.',
    ],
    'jobs' => [
        'nav' => 'Jobs & Runs',
        'title' => 'Jobs & Runs',
        'intro' => 'Job thực thi với lease/unknown. dispatched ≠ completed.',
        'empty' => 'Chưa có job.',
    ],
];
