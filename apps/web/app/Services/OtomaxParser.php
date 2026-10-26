<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OtomaxParser
{
    public const FIELDS = ['id_transaksi', 'kode_produk', 'tujuan', 'status', 'sn', 'refid', 'harga', 'saldo_awal', 'saldo_akhir', 'tanggal', 'jam', 'keterangan', 'saldo', 'amount'];

    public static function defaults(): array
    {
        return ['text_status_map' => ['SUKSES' => 'success', 'BERHASIL' => 'success', 'GAGAL' => 'failed', 'PENDING' => 'pending', 'PROSES' => 'pending'], 'message_field' => 'message', 'ref_field' => 'refid', 'status_field' => 'status', 'price_field' => 'price', 'status_map' => ['20' => 'success', '40' => 'failed', '1' => 'pending'], 'rules' => [
            ['name' => 'Sukses OtomaX', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'template' => '#[id_transaksi] [kode_produk].[tujuan] SUKSES. SN/Ref: [sn]. Saldo [saldo_awal] - [harga] = [saldo_akhir] @[tanggal] [jam] R#[refid]'],
            ['name' => 'Permintaan diterima', 'event' => 'transaction', 'outcome' => 'pending', 'active' => true, 'template' => '#[id_transaksi] [kode_produk].[tujuan] akan diproses @[jam]. Saldo [saldo_awal] - [harga] = [saldo_akhir]'],
            ['name' => 'Saldo akun', 'event' => 'balance', 'outcome' => 'pending', 'active' => true, 'template' => 'Informasi Akun Anda Nama : [keterangan] ID Agen : [id_transaksi] Saldo : [saldo] Bonus : [amount] Debet : [harga]'],
        ]];
    }

    public function validate(array $config): array
    {
        if (strlen(json_encode($config)) > 100000) {
            throw ValidationException::withMessages(['config' => 'Kelompok jawaban terlalu besar.']);
        }
        Validator::make($config, ['message_field' => 'required|string|max:50', 'ref_field' => 'required|string|max:50', 'status_field' => 'required|string|max:50', 'price_field' => 'required|string|max:50', 'text_status_map' => 'sometimes|array|max:30', 'text_status_map.*' => 'required|in:success,failed,pending', 'status_map' => 'present|array|max:20', 'status_map.*' => 'required|in:success,failed,pending', 'rules' => 'required|array|min:1|max:100', 'rules.*.name' => 'required|string|max:100', 'rules.*.event' => 'required|in:transaction,callback,status,balance,ticket', 'rules.*.outcome' => 'required|in:success,failed,pending', 'rules.*.active' => 'required|boolean', 'rules.*.template' => 'nullable|string|max:3000', 'rules.*.matcher' => 'sometimes|in:template,boundaries,regex', 'rules.*.keyword' => 'nullable|string|max:500', 'rules.*.regex' => 'nullable|string|max:3000', 'rules.*.case_sensitive' => 'sometimes|boolean', 'rules.*.priority' => 'sometimes|integer|min:0|max:9999', 'rules.*.captures' => 'sometimes|array|max:14', 'rules.*.captures.*.field' => 'required|in:'.implode(',', self::FIELDS), 'rules.*.captures.*.prefix' => 'present|nullable|string|max:500', 'rules.*.captures.*.suffix' => 'present|nullable|string|max:500', 'rules.*.captures.*.required' => 'required|boolean'])->validate();
        foreach ($config['rules'] as $r) {
            $this->validateRule($r);
        }

        return $config;
    }

    public static function examples(): array
    {
        $capture = fn (string $field, string $prefix, string $suffix): array => compact('field', 'prefix', 'suffix') + ['required' => true];

        return [
            ['name' => 'Sukses OtomaX', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'matcher' => 'boundaries', 'template' => '', 'keyword' => ' SUKSES.', 'case_sensitive' => false, 'priority' => 0, 'captures' => [
                $capture('id_transaksi', '#', ' '), $capture('kode_produk', ' ', '.'), $capture('tujuan', '.', ' SUKSES.'), $capture('sn', 'SN/Ref: ', '. Saldo '), $capture('saldo_awal', 'Saldo ', ' - '), $capture('harga', ' - ', ' = '), $capture('saldo_akhir', ' = ', ' @'), $capture('tanggal', '@', ' '), $capture('jam', ' ', ' R#'), $capture('refid', ' R#', ''),
            ]],
            ['name' => 'Permintaan diterima', 'event' => 'transaction', 'outcome' => 'pending', 'active' => true, 'matcher' => 'boundaries', 'template' => '', 'keyword' => ' akan diproses ', 'case_sensitive' => false, 'priority' => 10, 'captures' => [
                $capture('id_transaksi', '#', ' '), $capture('kode_produk', ' ', '.'), $capture('tujuan', '.', ' akan diproses'), $capture('saldo_awal', 'Saldo ', ' - '), $capture('harga', ' - ', ' = '), $capture('saldo_akhir', ' = ', ''),
            ]],
            ['name' => 'Saldo akun', 'event' => 'balance', 'outcome' => 'pending', 'active' => true, 'matcher' => 'boundaries', 'template' => '', 'keyword' => 'Informasi Akun Anda', 'case_sensitive' => false, 'priority' => 20, 'captures' => [$capture('saldo', 'Saldo : ', ' Bonus : ')]],
        ];
    }

    private function orderedRules(array $config): array
    {
        $rules = [];
        foreach ($config['rules'] ?? [] as $index => $rule) {
            $rules[] = [...$rule, '_index' => $index];
        }
        usort($rules, fn ($a, $b) => [($a['priority'] ?? $a['_index']), $a['_index']] <=> [($b['priority'] ?? $b['_index']), $b['_index']]);

        return $rules;
    }

    private function validateRule(array $rule): void
    {
        $mode = $rule['matcher'] ?? 'template';
        if ($mode === 'template') {
            if (! filled($rule['template'] ?? null)) {
                throw ValidationException::withMessages(['config' => 'Isi template penangkap '.$rule['name'].'.']);
            }
            $this->pattern($rule['template']);

            return;
        }
        if ($mode === 'regex') {
            $raw = $rule['regex'] ?? '';
            if (! filled($raw) || str_contains($raw, chr(31)) || str_contains($raw, '(*') || preg_match('/\(\?(?:R|0|&|P>)/', $raw)) {
                throw ValidationException::withMessages(['config' => 'RegEx kosong atau berisi perintah rekursif/kontrol yang tidak didukung.']);
            }
            preg_match_all('/\(\?(?:P?<|\x27)([a-zA-Z_][a-zA-Z0-9_]*)/', $raw, $groups);
            if (! $groups[1] || array_diff($groups[1], self::FIELDS)) {
                throw ValidationException::withMessages(['config' => 'Gunakan named group sesuai nama field, misalnya (?<tujuan>\\d+).']);
            }
            $pattern = $this->rawPattern($raw, $rule);
            if (@preg_match($pattern, '') === false) {
                throw ValidationException::withMessages(['config' => 'Sintaks RegEx tidak valid.']);
            }

            return;
        }
        if (! filled($rule['keyword'] ?? null) || empty($rule['captures'])) {
            throw ValidationException::withMessages(['config' => 'Isi kata kunci dan minimal satu data tangkapan pada '.$rule['name'].'.']);
        }
        $seen = [];
        foreach ($rule['captures'] as $capture) {
            if (in_array($capture['field'], $seen, true) || ((string) ($capture['prefix'] ?? '') === '' && (string) ($capture['suffix'] ?? '') === '')) {
                throw ValidationException::withMessages(['config' => 'Field tidak boleh berulang. Isi minimal awalan atau akhiran untuk tiap tangkapan.']);
            }
            $seen[] = $capture['field'];
        }
    }

    private function rawPattern(string $raw, array $rule): string
    {
        return chr(31).'(*NO_JIT)(*LIMIT_MATCH=20000)(*LIMIT_DEPTH=100)'.$raw.chr(31).'su'.(($rule['case_sensitive'] ?? true) ? '' : 'i');
    }

    private function captureType(string $field, bool $hasSuffix): string
    {
        if ($field === 'jam') {
            return '[0-9]{2}:[0-9]{2}(?::[0-9]{2})?';
        }
        if (in_array($field, ['harga', 'saldo', 'saldo_awal', 'saldo_akhir', 'amount'], true)) {
            return '[0-9]+(?:[.,][0-9]+)*';
        }
        if (in_array($field, ['id_transaksi', 'kode_produk', 'tujuan', 'refid'], true)) {
            return $hasSuffix ? '\\S+?' : '\\S+';
        }

        return $hasSuffix ? '.+?' : '.+';
    }

    public function matchRule(array $rule, string $message): array
    {
        $mode = $rule['matcher'] ?? 'template';
        $patterns = [];
        $missing = [];
        $fields = [];
        $spans = [];
        if ($mode === 'boundaries') {
            $keyword = (string) ($rule['keyword'] ?? '');
            $found = $keyword !== '' && (($rule['case_sensitive'] ?? true) ? str_contains($message, $keyword) : mb_stripos($message, $keyword) !== false);
            if (! $found) {
                return ['matched' => false, 'fields' => [], 'captures' => [], 'error' => null, 'reason' => 'Kata kunci tidak ditemukan.', 'expression' => ''];
            }
            foreach ($rule['captures'] ?? [] as $capture) {
                $prefix = (string) ($capture['prefix'] ?? '');
                $suffix = (string) ($capture['suffix'] ?? '');
                $field = $capture['field'];
                $raw = ($prefix === '' ? '^' : preg_quote($prefix, chr(31))).'(?<'.$field.'>'.$this->captureType($field, $suffix !== '').')'.($suffix === '' ? '\\s*$' : preg_quote($suffix, chr(31)));
                $patterns[] = ['expression' => $this->rawPattern($raw, $rule), 'required' => $capture['required'] ?? true, 'field' => $field];
            }
        } elseif ($mode === 'regex') {
            $patterns[] = ['expression' => $this->rawPattern($rule['regex'], $rule), 'required' => true, 'field' => 'RegEx'];
        } else {
            $patterns[] = ['expression' => $this->pattern($rule['template']), 'required' => true, 'field' => 'Template'];
        }
        foreach ($patterns as $pattern) {
            $matched = @preg_match($pattern['expression'], $message, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            if ($matched === false) {
                return ['matched' => false, 'fields' => [], 'captures' => [], 'error' => 'RegEx tidak valid atau melampaui batas pemrosesan.', 'reason' => preg_last_error_msg(), 'expression' => ''];
            }
            if ($matched !== 1) {
                if ($pattern['required']) {
                    $missing[] = $pattern['field'];
                }

                continue;
            }
            foreach ($matches as $field => $match) {
                if (! is_string($field) || ! in_array($field, self::FIELDS, true) || $match[1] < 0 || $match[0] === null) {
                    continue;
                }
                $value = trim($match[0]);
                if ($value === '') {
                    continue;
                }$offset = $match[1] + strlen($match[0]) - strlen(ltrim($match[0]));
                $fields[$field] = $value;
                $spans[] = ['field' => $field, 'value' => $value, 'start' => mb_strlen(substr($message, 0, $offset)), 'length' => mb_strlen($value)];
            }
        }

        return ['matched' => ! $missing && count($patterns) > 0 && ($mode === 'template' || count($fields) > 0), 'fields' => $fields, 'captures' => $spans, 'error' => null, 'reason' => $missing ? 'Belum tertangkap: '.implode(', ', $missing).'.' : (count($fields) ? 'Data berhasil ditangkap.' : 'Tidak ada field yang tertangkap.'), 'expression' => implode("\n", array_column($patterns, 'expression'))];
    }

    public function inspect(array $config, string $event, string|array $input): array
    {
        $message = is_array($input) ? (string) ($input[$config['message_field']] ?? '') : $input;
        $checks = [];
        foreach ($this->orderedRules($config) as $rule) {
            $eligible = ($rule['active'] ?? false) && $rule['event'] === $event;
            $match = $eligible ? $this->matchRule($rule, $message) : ['matched' => false, 'fields' => [], 'captures' => [], 'error' => null, 'reason' => ! ($rule['active'] ?? false) ? 'Penangkap nonaktif.' : 'Jenis jawaban berbeda.', 'expression' => ''];
            if ($match['matched'] && isset($match['fields']['status']) && ($config['text_status_map'][mb_strtoupper($match['fields']['status'])] ?? null) !== $rule['outcome']) {
                $match['reason'] = 'Status teks tidak sesuai hasil penangkap ini.';
                $match['matched'] = false;
            }
            $checks[] = ['index' => $rule['_index'], 'name' => $rule['name'], 'outcome' => $rule['outcome'], 'event' => $rule['event'], 'priority' => $rule['priority'] ?? $rule['_index'], 'eligible' => $eligible, ...$match];
        }

        return [...$this->parse($config, $event, $input), 'checks' => $checks, 'message' => $message];
    }

    public function pattern(string $template): string
    {
        $parts = preg_split('/(\[[a-z_]+\])/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $seen = [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\[([a-z_]+)\]$/', $part, $m)) {
                $key = $m[1];
                if (! in_array($key, self::FIELDS, true) || in_array($key, $seen, true)) {
                    throw ValidationException::withMessages(['template' => 'Placeholder tidak dikenal atau berulang: '.$key]);
                }
                $seen[] = $key;
                $type = in_array($key, ['harga', 'saldo', 'saldo_awal', 'saldo_akhir', 'amount'], true) ? '[0-9.,]+' : (in_array($key, ['kode_produk', 'tujuan', 'refid', 'id_transaksi'], true) ? '[^\s]+?' : '.+?');
                $regex .= '(?P<'.$key.'>'.$type.')';
            } else {
                $regex .= preg_replace('/\s+/', '\\s+', preg_quote($part, '~'));
            }
        }
        if (str_contains($template, '][')) {
            throw ValidationException::withMessages(['template' => 'Pisahkan placeholder dengan teks atau spasi.']);
        }

        return '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)^\s*'.$regex.'\s*$~su';
    }

    public static function amount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 1000000000000 ? $value : null;
        }
        $s = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{3})*(?:,0+)?$/', $s)) {
            return null;
        }

        return strlen(str_replace(['.', ','], '', $s)) <= 13 ? (int) str_replace('.', '', explode(',', $s)[0]) : null;
    }

    public function parse(array $config, string $event, string|array $input): array
    {
        $fields = [];
        $outcome = null;
        $names = [];
        $message = is_array($input) ? (string) ($input[$config['message_field'] ?? 'message'] ?? '') : $input;
        if (strlen($message) > 20000) {
            return ['status' => 'pending', 'fields' => [], 'matched' => [], 'error' => 'Respons melebihi batas ukuran.'];
        }
        foreach ($this->orderedRules($config) as $rule) {
            if (! ($rule['active'] ?? false) || ($rule['event'] ?? '') !== $event) {
                continue;
            }
            $capture = $this->matchRule($rule, $message);
            if ($capture['error']) {
                return ['status' => 'pending', 'fields' => [], 'matched' => [], 'error' => 'Penangkap '.$rule['name'].': '.$capture['error']];
            }
            if (! $capture['matched']) {
                continue;
            }
            $values = $capture['fields'];
            if (isset($values['status'])) {
                $mappedText = $config['text_status_map'][mb_strtoupper(trim($values['status']))] ?? null;
                if (! $mappedText) {
                    return ['status' => 'pending', 'fields' => $values, 'matched' => [$rule['name']], 'error' => 'Status teks belum dipetakan.'];
                } if ($mappedText !== $rule['outcome']) {
                    continue;
                }
            }
            if ($outcome !== null && ($outcome !== $rule['outcome'] || array_diff_assoc(array_intersect_key($fields, $values), $values))) {
                return ['status' => 'pending', 'fields' => [], 'matched' => [...$names, $rule['name']], 'error' => 'Beberapa pola menghasilkan data berbeda.'];
            }
            $fields = [...$values, ...$fields];
            $outcome = $rule['outcome'];
            $names[] = $rule['name'];
        }
        if ($outcome === null && in_array($event, ['transaction', 'status', 'callback'], true)) {
            $duplicate = $this->matchRule([
                'matcher' => 'template', 'case_sensitive' => false,
                'template' => '[kode_produk].[tujuan] sdh pernah jam [jam] Hrg [harga], status [status]. SN/Ref: [sn]. Trx [keterangan]. Saldo [saldo] #[id_transaksi] R#[refid]',
            ], $message);
            if ($duplicate['matched'] && ! $duplicate['error']) {
                $mapped = $config['text_status_map'][mb_strtoupper(trim($duplicate['fields']['status']))] ?? null;
                if ($mapped !== null) {
                    $fields = $duplicate['fields'];
                    $outcome = $mapped;
                    $names[] = 'Jawaban standar sdh pernah OtomaX';
                }
            }
        }
        if (is_array($input)) {
            foreach (['refid' => 'ref_field', 'harga' => 'price_field'] as $key => $setting) {
                $v = $input[$config[$setting] ?? ($key === 'harga' ? 'price' : 'refid')] ?? null;
                if (! is_scalar($v) || $v === '') {
                    continue;
                }
                if (isset($fields[$key]) && ($key === 'harga' ? self::amount($fields[$key]) !== self::amount($v) : (string) $fields[$key] !== (string) $v)) {
                    return ['status' => 'pending', 'fields' => [], 'matched' => $names, 'error' => 'Query dan pesan pemasok bertentangan.'];
                }
                $fields[$key] = (string) $v;
            }
            $raw = $input[$config['status_field'] ?? 'status'] ?? null;
            $mapped = is_scalar($raw) ? ($config['status_map'][(string) $raw] ?? null) : null;
            if ($outcome !== null && $mapped !== null && $outcome !== $mapped) {
                return ['status' => 'pending', 'fields' => [], 'matched' => $names, 'error' => 'Status query dan template bertentangan.'];
            }
            $outcome ??= $mapped;
        }

        return ['status' => $outcome ?? 'pending', 'fields' => $fields, 'matched' => $names, 'error' => $outcome === null ? 'Belum ada pola/status yang cocok.' : null];
    }
}
