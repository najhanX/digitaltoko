<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HanshopIdentity
{
    public function ready(): bool
    {
        return filled(config('services.hanshop.url')) && strlen((string) config('services.hanshop.token')) >= 32;
    }

    public function call(string $path, array $data): array
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['identifier' => 'Koneksi akun Hanshop belum dikonfigurasi.']);
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->withToken(config('services.hanshop.token'))
                ->connectTimeout(5)->timeout(30)
                ->post(rtrim(config('services.hanshop.url'), '/').'/internal/digitaltoko/'.$path, $data);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages(['identifier' => 'Hanshop belum dapat dihubungi. Silakan coba kembali.']);
        }
        if (! $response->successful() || $response->json('status') !== 'SUCCESS') {
            throw ValidationException::withMessages(['identifier' => 'Permintaan akun Hanshop gagal. Periksa data atau coba kembali.']);
        }
        $payload = $response->json('data');
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['identifier' => 'Respons akun Hanshop tidak valid.']);
        }
        if (in_array($path, ['identity', 'register', 'profile'], true)) {
            $user = $payload['user'] ?? null;
            if (! array_key_exists('user', $payload) || ($path !== 'identity' && ! is_array($user)) || ($user !== null && (! is_array($user) || Validator::make($user, [
                'id' => ['required', 'string', 'regex:/^HD[0-9]+$/'], 'name' => 'required|string|max:150',
                'phone' => ['required', 'string', 'regex:/^62[1-9][0-9]{7,12}$/'],
                'email' => 'present|nullable|email:rfc|max:255', 'email_verified_at' => 'present|nullable|date',
            ])->fails()))) {
                throw ValidationException::withMessages(['identifier' => 'Respons identitas Hanshop tidak valid.']);
            }
            if (is_array($user)) {
                $payload['user'] = array_intersect_key($user, array_flip(['id', 'name', 'phone', 'email', 'email_verified_at']));
            }
        }

        return $payload;
    }

    public static function phone(string $input): string
    {
        $phone = preg_replace('/[\s()+-]/', '', $input);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        if (! preg_match('/^62[1-9][0-9]{7,12}$/', $phone)) {
            throw ValidationException::withMessages(['identifier' => 'Gunakan nomor WhatsApp Indonesia yang valid.']);
        }

        return $phone;
    }
}
