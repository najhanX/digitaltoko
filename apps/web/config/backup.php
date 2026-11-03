<?php

return [
    'live_enabled' => (bool) env('BACKUP_LIVE_RESTORE_ENABLED', false),
    'restore_lock' => storage_path('framework/live-restore.lock'),
    'restore_hold' => storage_path('framework/restore-hold.json'),
    'pm2_cli' => env('BACKUP_PM2_CLI') ?: (PHP_OS_FAMILY === 'Windows' ? getenv('APPDATA').'/npm/node_modules/pm2/bin/pm2' : null),
    'node' => env('BACKUP_NODE_BINARY', 'node'),
    'preview' => (bool) env('RECOVERY_PREVIEW', false),
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'key' => env('BACKUP_KEY'),
    'directory' => env('BACKUP_DIRECTORY') ?: storage_path('app/backups'),
    'upload_max_mb' => (int) env('BACKUP_UPLOAD_MAX_MB', 512),
    'replica' => env('BACKUP_REPLICA_DIRECTORY'),
    'time' => env('BACKUP_TIME', '03:00'),
    'keep' => (int) env('BACKUP_KEEP', 7),
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 30),
    'restore_limit_bytes' => (int) env('BACKUP_RESTORE_LIMIT_BYTES', 21474836480),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    'sources' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
        'whatsapp' => env('BACKUP_BOT_SESSIONS') ?: base_path('../bot/sessions'),
    ],
];
