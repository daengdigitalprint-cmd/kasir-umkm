<?php

return [
    'rclone' => [
        'remote' => env('RCLONE_REMOTE', ''),
        'binary' => env('RCLONE_BINARY', 'rclone'),
        'retention_days' => env('RCLONE_RETENTION_DAYS', 30),
    ],

    'backup' => [
        'name' => env('BACKUP_NAME', 'laravel-backup'),
        'destination' => [
            'disks' => ['local'],
        ],
    ],
];
