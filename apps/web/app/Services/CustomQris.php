<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CustomQris
{
    private function crc(string $value): string
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($value); $i++) {
            $crc ^= ord($value[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : ($crc << 1)) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function fields(string $payload): array
    {
        $fields = [];
        $offset = 0;
        while ($offset < strlen($payload)) {
            $tag = substr($payload, $offset, 2);
            $length = substr($payload, $offset + 2, 2);
            if (! ctype_digit($tag.$length) || strlen($length) !== 2 || isset($fields[$tag]) || $offset + 4 + (int) $length > strlen($payload)) {
                throw ValidationException::withMessages(['qris_payload' => 'Payload QRIS tidak valid.']);
            }
            $fields[$tag] = substr($payload, $offset + 4, (int) $length);
            $offset += 4 + (int) $length;
        }
        if (($fields['00'] ?? '') !== '01' || ($fields['53'] ?? '') !== '360' || ($fields['58'] ?? '') !== 'ID' || substr($payload, -8, 4) !== '6304' || ($fields['63'] ?? '') !== $this->crc(substr($payload, 0, -4))) {
            throw ValidationException::withMessages(['qris_payload' => 'Gunakan payload QRIS Indonesia dengan checksum yang valid.']);
        }

        return $fields;
    }

    public function identity(string $payload): string
    {
        $fields = $this->fields($payload);
        unset($fields['01'],$fields['54'],$fields['63']);

        return hash('sha256', json_encode($fields));
    }

    public function amount(string $payload, int $amount): string
    {
        $fields = $this->fields($payload);
        unset($fields['63'],$fields['54']);
        $fields['01'] = '12';
        $fields['54'] = (string) $amount;
        ksort($fields);
        $value = '';
        foreach ($fields as $tag => $item) {
            $value .= str_pad((string) $tag, 2, '0', STR_PAD_LEFT).str_pad((string) strlen($item), 2, '0', STR_PAD_LEFT).$item;
        }
        $value .= '6304';

        return $value.$this->crc($value);
    }

    public function image(string $payload): string
    {
        if (! app(BotManager::class)->configured()) {
            throw ValidationException::withMessages(['payment' => 'Layanan QR lokal belum dikonfigurasi administrator.']);
        }
        $response = Http::withToken(config('services.bot_manager.token'))->acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(8)->post(rtrim(config('services.bot_manager.url'), '/').'/qr', ['payload' => $payload]);
        $image = $response->json('data');
        if (! $response->successful() || ! is_string($image) || strlen($image) > 200000 || ! preg_match('/^data:image\/png;base64,[a-zA-Z0-9+\/=]+$/', $image)) {
            throw ValidationException::withMessages(['payment' => 'QR pembayaran belum dapat dibuat. Silakan coba kembali.']);
        }

        return $image;
    }
}
