<?php

namespace App\Support;

class ForwarderDownloads
{
    public static function available(): array
    {
        $apk = config('services.forwarder.apk_path');

        return [
            'apk' => is_string($apk) && is_file($apk) ? route('forwarder.download') : null,
            'tools' => collect(['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux'])->map(function ($label, $os) {
                $path = config('services.forwarder.tools.'.$os);

                return ['os' => $os, 'label' => $label, 'url' => is_string($path) && is_file($path) ? route('forwarder.tools', ['os' => $os]) : null];
            })->values()->all(),
        ];
    }
}
