<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QrisNotificationParser
{
    public function defaults(): array
    {
        return ['name' => 'Pembayaran masuk', 'field' => 'text', 'excluded' => "promo\ngagal\ndibatalkan\nkeluar\nberhasil dikirim", 'rules' => [
            ['name' => 'DANA Bisnis', 'template' => 'Rp[nominal] diterima DANA Bisnis.', 'active' => true],
            ['name' => 'Pembayaran diterima', 'template' => 'Rp[nominal] pembayaran diterima.', 'active' => true],
            ['name' => 'Pembayaran diterima tanpa titik', 'template' => 'Rp[nominal] pembayaran diterima', 'active' => true],
        ]];
    }

    public function validate(array $input): array
    {
        $config = Validator::make($input, [
            'name' => 'required|string|max:80', 'field' => 'required|in:text,combined', 'excluded' => 'present|nullable|string|max:1000',
            'rules' => 'required|array|min:1|max:10', 'rules.*' => 'array:name,template,active',
            'rules.*.name' => 'required|string|max:80', 'rules.*.template' => 'required|string|max:1000', 'rules.*.active' => 'required|boolean',
        ])->validate();
        $config['excluded'] ??= '';
        $active = false;
        foreach ($config['rules'] as $index => $rule) {
            $this->pattern($rule['template'], 'rules.'.$index.'.template');
            $active = $active || $rule['active'];
        }
        if (! $active) {
            throw ValidationException::withMessages(['rules' => 'Aktifkan minimal satu format pembayaran masuk.']);
        }

        return $config;
    }

    private function pattern(string $template, string $key = 'parser'): string
    {
        $template = trim($template);
        preg_match_all('/\[([^\]]*)\]/u', $template, $tokens);
        $allowed = ['nominal', 'referensi', 'pengirim', 'waktu', 'abaikan'];
        $literal = preg_replace('/\[[^\]]*\]/u', '', $template);
        if (count(array_filter($tokens[1], fn ($name) => $name === 'nominal')) !== 1
            || count(array_diff($tokens[1], $allowed)) || count($tokens[1]) !== count(array_unique($tokens[1]))
            || mb_strlen(trim($literal)) < 8 || str_contains($literal, '[') || str_contains($literal, ']')) {
            throw ValidationException::withMessages([$key => 'Gunakan [nominal] tepat sekali, teks tetap minimal 8 karakter, dan placeholder yang tersedia tanpa pengulangan.']);
        }
        $parts = preg_split('/(\[(?:nominal|referensi|pengirim|waktu|abaikan)\])/u', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';
        foreach ($parts as $part) {
            if (preg_match('/^\[(.+)\]$/', $part, $token)) {
                $pattern .= $token[1] === 'nominal' ? '(?<nominal>(?:[1-9][0-9]{0,2}(?:\.[0-9]{3})+|[1-9][0-9]*)(?:,00)?)' : '(?<'.$token[1].'>[^\r\n]{1,300}?)';
            } else {
                $pattern .= preg_replace('/\s+/u', '\\s+', preg_quote($part, '~'));
            }
        }

        return '~\A'.$pattern.'\z~iu';
    }

    public function parse(array $config, array $notification): array
    {
        $text = trim(($config['field'] === 'combined' ? ($notification['title'] ?? '')."\n" : '').($notification['text'] ?? ''));
        $allText = ($notification['title'] ?? '').' '.($notification['text'] ?? '');
        $rejected = fn (string $reason) => ['matched' => false, 'reason' => $reason, 'amount' => null, 'rule' => null, 'fields' => []];
        if (mb_strlen($text) > 4500) {
            return $rejected('Pesan terlalu panjang.');
        }
        // Failure words remain blocked even when an operator removes custom exclusions.
        $excluded = array_merge(['gagal', 'dibatalkan', 'berhasil dikirim'], preg_split('/\R/u', $config['excluded'] ?? ''));
        foreach ($excluded as $word) {
            if (trim($word) !== '' && mb_stripos($allText, trim($word)) !== false) {
                return $rejected('Pesan mengandung kata pengecualian: '.trim($word).'.');
            }
        }
        $matches = [];
        foreach ($config['rules'] as $rule) {
            if (! $rule['active'] || preg_match($this->pattern($rule['template']), $text, $fields) !== 1) {
                continue;
            }
            $amount = (int) str_replace('.', '', explode(',', $fields['nominal'])[0]);
            if ($amount < 1 || $amount > 1000000000) {
                return $rejected('Nominal di luar batas pembayaran.');
            }
            $captured = array_filter($fields, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
            $matches[] = ['matched' => true, 'reason' => 'Format cocok. Invoice belum diperiksa pada uji ini.', 'amount' => $amount, 'rule' => $rule['name'], 'fields' => $captured];
        }
        if (! $matches) {
            return $rejected('Tidak ada format aktif yang cocok. Periksa teks lengkap dan tanda baca.');
        }
        if (count(array_unique(array_map(fn ($match) => json_encode([$match['amount'], $match['fields']['referensi'] ?? null]), $matches))) > 1) {
            return $rejected('Beberapa format menghasilkan data berbeda. Perjelas format agar tidak ambigu.');
        }

        return $matches[0];
    }
}
