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

    /*
     * Retensi backup lokal (storage/app/private/laravel-backup), dijalankan
     * oleh `backup:clean` yang dijadwalkan tiap malam di bootstrap/app.php.
     * Satu backup ±350 MB; default di bawah menyimpan ±11 file (≈4 GB):
     * semua backup 7 hari terakhir, 1 per hari 7 hari sebelumnya, 1 per minggu 4 minggu.
     * Backup terbaru tidak pernah dihapus walau melewati batas ukuran.
     */
    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => (int) env('BACKUP_KEEP_ALL_DAYS', 7),
            'keep_daily_backups_for_days' => (int) env('BACKUP_KEEP_DAILY_DAYS', 7),
            'keep_weekly_backups_for_weeks' => (int) env('BACKUP_KEEP_WEEKLY_WEEKS', 4),
            'keep_monthly_backups_for_months' => (int) env('BACKUP_KEEP_MONTHLY_MONTHS', 0),
            'keep_yearly_backups_for_years' => (int) env('BACKUP_KEEP_YEARLY_YEARS', 0),
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_MAX_MB', 4000),
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
];
