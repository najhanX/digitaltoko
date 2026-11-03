<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class RecoveryPreview
{
    public function prepare(string $id, int $port): string
    {
        if ($port < 1024 || $port > 65535 || in_array($port, [8000, 3099, 4000, 5173], true)) {
            throw new RuntimeException('Gunakan port pratinjau 1024–65535 yang terpisah, misalnya 8011.');
        }
        $folder = app(BackupRecovery::class)->destination($id);
        $marker = $folder.'/RESTORED.json';
        if (! is_file($marker) || is_link($folder)) {
            throw new RuntimeException('Hasil pemulihan terpisah belum lengkap.');
        }
        $data = json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
        $database = 'dt_restore_'.str_replace('-', '', $id);
        if (($data['database'] ?? '') !== $database || $database === DB::connection()->getDatabaseName()) {
            throw new RuntimeException('Database pratinjau tidak sesuai hasil restore.');
        }
        $env = file_get_contents($folder.'/.env.recovery');
        if (! preg_match('/^APP_KEY=(.+)$/m', $env, $matches)) {
            throw new RuntimeException('APP_KEY hasil pemulihan tidak tersedia.');
        }
        $runtime = $folder.'/preview-runtime';
        if (is_link($runtime)) {
            throw new RuntimeException('Direktori preview tidak valid.');
        }
        foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private', 'app/public', 'app/backups'] as $path) {
            File::ensureDirectoryExists($runtime.'/'.$path, 0700);
        }
        foreach (['private', 'public'] as $alias) {
            if (is_dir($folder.'/files/'.$alias)) {
                if (! File::copyDirectory($folder.'/files/'.$alias, $runtime.'/app/'.$alias)) {
                    throw new RuntimeException('Media pratinjau gagal disalin.');
                }
            }
        }
        $db = config('database.connections.mysql');
        $settings = ['env' => [
            'APP_ENV' => 'recovery-preview', 'APP_DEBUG' => 'false', 'APP_KEY' => trim($matches[1]), 'APP_URL' => 'http://localhost:'.$port,
            'APP_CONFIG_CACHE' => $runtime.'/config.php', 'APP_ROUTES_CACHE' => $runtime.'/routes.php',
            'LARAVEL_STORAGE_PATH' => $runtime, 'RECOVERY_PREVIEW' => 'true', 'BACKUP_ENABLED' => 'false', 'BACKUP_LIVE_RESTORE_ENABLED' => 'false',
            'BACKUP_DIRECTORY' => $runtime.'/app/backups', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => $database,
            'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'), 'DB_PORT' => (string) ($db['port'] ?? 3306), 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'PLATFORM_DOMAIN' => 'localhost', 'PLATFORM_DOMAIN_ALIASES' => '127.0.0.1', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null',
            'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'recovery_'.str_replace('-', '', $id), 'SESSION_SECURE_COOKIE' => 'false',
        ], 'public' => public_path()];
        file_put_contents($runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        chmod($runtime.'/settings.json', 0600);
        $router = <<<'PHP'
<?php
$settings=json_decode(file_get_contents(__DIR__.'/settings.json'),true,512,JSON_THROW_ON_ERROR);
foreach($settings['env'] as $key=>$value){$_ENV[$key]=$value;$_SERVER[$key]=$value;putenv($key.'='.$value);}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath($settings['public'].$path);
if(!str_starts_with($path,'/storage/')&&$file&&is_file($file)&&str_starts_with($file,realpath($settings['public']).DIRECTORY_SEPARATOR)&&strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='php'){return false;}
require $settings['public'].'/index.php';
PHP;
        file_put_contents($runtime.'/router.php', $router);
        chmod($runtime.'/router.php', 0600);

        return $runtime.'/router.php';
    }
}
