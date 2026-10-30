<?php

namespace App\Services;

use App\Models\OtomaxProfile;
use App\Models\SupplierConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OtomaxClient
{
    public static function sign(string $member, string $pin, string $password, string $event, array $data = []): string
    {
        $parts = match ($event) {
            'balance' => ['OtomaX', 'CheckBalance', $member, $pin, $password],'ticket' => ['OtomaX', 'ticket', $member, $pin, $password, (string) $data['amount']],default => ['OtomaX', $member, $data['product'], $data['dest'], $data['refID'], $pin, $password]
        };

        return rtrim(strtr(base64_encode(sha1(implode('|', $parts), true)), '+/', '-_'), '=');
    }

    public function endpoint(array $settings): array
    {
        $url = rtrim((string) ($settings['base_url'] ?? ''), '/');
        if ($target = app(SupplierSimulator::class)->target($url)) {
            return $target;
        }
        $p = parse_url($url);
        if (! $p || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || ($p['path'] ?? '') !== '') {
            throw ValidationException::withMessages(['base_url' => 'Gunakan http(s)://IP-atau-domain:port tanpa path atau kredensial.']);
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (! $ips || count($ips) > 20) {
            throw ValidationException::withMessages(['base_url' => 'Host supplier tidak dapat diresolusi.']);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages(['base_url' => 'Alamat harus IP publik supplier, bukan jaringan internal.']);
            }
        }
        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['base_url' => 'Port tidak valid.']);
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function profile(SupplierConnection $c): array
    {
        return OtomaxProfile::where('store_id', $c->store_id)->where('type', 'response')->findOrFail($c->response_profile_id)->config;
    }

    public function send(SupplierConnection $c, string $event, array $data = [], ?array $snapshot = null): array
    {
        $simulator = app(SupplierSimulator::class)->connection($c, $snapshot);
        if ($c->store->sandbox && ! $simulator) {
            return ['status' => $event === 'transaction' ? 'success' : 'pending', 'fields' => ['saldo' => '1000000', 'harga' => (string) ($data['cost'] ?? 0), 'sn' => 'SIMULASI OTOMAX', 'refid' => $data['refID'] ?? ''], 'matched' => ['Simulasi lokal'], 'error' => null, 'text' => $event === 'ticket' ? 'SIMULASI · tiket deposit, tidak perlu transfer.' : 'SIMULASI OTOMAX'];
        }
        $settings = $snapshot['settings'] ?? $c->settings;
        $credentials = $snapshot['credentials'] ?? $c->credentials;
        $profile = $snapshot['profile'] ?? $this->profile($c);
        $target = $this->endpoint($settings);
        $path = match ($event) {
            'balance' => $settings['balance_path'] ?? '/balance','ticket' => $settings['ticket_path'] ?? '/ticket','status' => $settings['status_path'] ?? '',default => $settings['transaction_path'] ?? '/trx'
        };
        if (! preg_match('~^/[a-zA-Z0-9/_-]{1,100}$~', $path)) {
            throw ValidationException::withMessages(['supplier' => 'Endpoint operasi ini belum dikonfigurasi.']);
        }
        $operation = $event === 'status' ? 'transaction' : $event;
        $params = ['memberID' => $credentials['member_id'], 'sign' => self::sign($credentials['member_id'], $credentials['pin'], $credentials['password'], $operation, $data)];
        if (in_array($event, ['transaction', 'status'], true)) {
            $params += [...array_intersect_key($data, array_flip(['product', 'dest', 'refID'])), 'qty' => 1];
            if ($simulator) {
                $params['cb_url'] = rtrim(config('app.url'), '/').'/api/commerce/otomax/'.$c->id.'/'.$credentials['callback_token'];
            }
            if ($event === 'status') {
                $params['cek'] = 1;
            }
        }
        if ($event === 'ticket') {
            $params['amount'] = $data['amount'];
            if (($settings['ticket_cmd'] ?? false)) {
                $params['cmd'] = 'ticket';
            }
        }
        try {
            $r = Http::withoutRedirecting()->connectTimeout(5)->timeout(20)->withOptions([...($simulator ? ['proxy' => ''] : []), 'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]]])->get($target['url'].$path, $params);
            if (! $r->successful() || strlen($r->body()) > 20000) {
                throw new \RuntimeException;
            }
            $body = $r->json();
            $text = $r->body();
            if (! is_array($body) && preg_match('/^(?:status|message|refid)=/', $text)) {
                parse_str($text, $query);
                $body = $query;
            }
            $parsed = app(OtomaxParser::class)->parse($profile, $event, is_array($body) ? $body : $text);

            $message = is_array($body) ? (string) ($body[$profile['message_field'] ?? 'message'] ?? $text) : $text;

            return [...$parsed, 'text' => $message];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['supplier' => 'Koneksi OtomaX belum memberikan hasil pasti. Periksa koneksi atau tunggu callback.']);
        }
    }

    public function snapshot(SupplierConnection $c): array
    {
        return ['connection_name' => $c->name, 'credentials' => $c->credentials, 'settings' => $c->settings, 'profile' => $this->profile($c), 'profile_id' => $c->response_profile_id, 'profile_version' => OtomaxProfile::find($c->response_profile_id)?->version];
    }
}
