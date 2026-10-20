<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class StoreMail
{
    public function ready(Store $store): bool
    {
        $settings = $store->notification_settings ?? [];
        if (($settings['mode'] ?? 'platform') === 'smtp') {
            return filled($settings['host'] ?? null) && filled($settings['password'] ?? null) && filled($settings['from_address'] ?? null);
        }

        return ! in_array(config('mail.default'), ['log', 'array'], true) && filled(config('mail.from.address'));
    }

    public function publicSettings(Store $store): array
    {
        $settings = $store->notification_settings ?? [];

        return [...array_intersect_key($settings, array_flip(['mode', 'host', 'port', 'username', 'from_address'])), 'mode' => $settings['mode'] ?? 'platform',
            'has_password' => filled($settings['password'] ?? null), 'ready' => $this->ready($store),
            'platform_ready' => ! in_array(config('mail.default'), ['log', 'array'], true) && filled(config('mail.from.address')),
            'platform_from' => config('mail.from.address')];
    }

    public function validateHost(string $host): string
    {
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            throw ValidationException::withMessages(['host' => 'Gunakan hostname SMTP publik, misalnya smtp.gmail.com.']);
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []));
        if (! $addresses || collect($addresses)->contains(fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))) {
            throw ValidationException::withMessages(['host' => 'Hostname SMTP harus mengarah ke alamat IP publik yang dapat dihubungi.']);
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $address;
            }
        }

        return '['.array_values($addresses)[0].']';
    }

    public function send(Store $store, string $to, Mailable $message): void
    {
        if (! $this->ready($store)) {
            throw ValidationException::withMessages(['email' => 'Email toko belum tersedia. Hubungi pengelola toko atau gunakan WhatsApp toko.']);
        }
        $settings = $store->notification_settings ?? [];
        $custom = ($settings['mode'] ?? 'platform') === 'smtp';
        $message->from($custom ? $settings['from_address'] : config('mail.from.address'), preg_replace('/[\r\n]+/', ' ', $store->name));
        try {
            if ($custom) {
                $address = $this->validateHost($settings['host']);
                $mailer = Mail::build(['transport' => 'smtp', 'host' => $address, 'port' => (int) $settings['port'],
                    'scheme' => (int) $settings['port'] === 465 ? 'smtps' : 'smtp', 'require_tls' => true,
                    'username' => $settings['username'], 'password' => $settings['password'], 'timeout' => 8]);
                $stream = $mailer->getSymfonyTransport()->getStream();
                $stream->setStreamOptions(['ssl' => ['peer_name' => $settings['host'], 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
                $mailer->to($to)->send($message);
            } else {
                Mail::to($to)->send($message);
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['email' => 'Email belum berhasil dikirim. Periksa pengaturan pengirim toko atau coba kembali.']);
        }
    }
}
