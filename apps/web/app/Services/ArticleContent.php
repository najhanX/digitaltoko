<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Validation\ValidationException;

class ArticleContent
{
    public function normalize(array $document, Store $store): array
    {
        $this->ensure(strlen(json_encode($document, JSON_THROW_ON_ERROR)) <= 250000);
        $count = 0;
        $walk = function (array $node, int $depth = 0, string $parent = '') use (&$walk, &$count, $store): array {
            $this->ensure(++$count <= 3000 && $depth <= 16);
            $type = $node['type'] ?? '';
            $allowed = match ($parent) {
                '' => ['doc'],
                'doc', 'blockquote' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'image', 'horizontalRule'],
                'bulletList', 'orderedList' => ['listItem'],
                'listItem' => ['paragraph', 'bulletList', 'orderedList', 'blockquote'],
                'paragraph', 'heading' => ['text', 'hardBreak'],
                default => [],
            };
            $this->ensure(is_string($type) && in_array($type, $allowed, true));
            $result = ['type' => $type];
            $attrs = $node['attrs'] ?? [];
            $this->ensure(is_array($attrs));
            if ($type === 'text') {
                $this->ensure(is_string($node['text'] ?? null) && mb_strlen($node['text']) <= 100000);
                $result['text'] = $node['text'];
                $marks = $node['marks'] ?? [];
                $this->ensure(is_array($marks) && count($marks) <= 10);
                $result['marks'] = array_map(fn ($mark) => $this->mark($mark), $marks);
            }
            if (in_array($type, ['paragraph', 'heading'], true)) {
                if (! empty($attrs['textAlign'])) {
                    $this->ensure(in_array($attrs['textAlign'], ['left', 'center', 'right', 'justify'], true));
                    $result['attrs']['textAlign'] = $attrs['textAlign'];
                }
                if ($type === 'heading') {
                    $this->ensure(in_array($attrs['level'] ?? null, [2, 3, 4], true));
                    $result['attrs']['level'] = $attrs['level'];
                }
            }
            if ($type === 'orderedList') {
                $start = $attrs['start'] ?? 1;
                $this->ensure(is_int($start) && $start >= 1 && $start <= 10000);
                $result['attrs']['start'] = $start;
            }
            if ($type === 'image') {
                $this->assetId($attrs['src'] ?? null, $store);
                $result['attrs'] = ['src' => $attrs['src']];
                foreach (['alt', 'title'] as $key) {
                    $this->ensure(! isset($attrs[$key]) || (is_string($attrs[$key]) && mb_strlen($attrs[$key]) <= 200));
                    $result['attrs'][$key] = $attrs[$key] ?? '';
                }
            }
            if (isset($node['content'])) {
                $this->ensure(is_array($node['content']) && array_is_list($node['content']) && ! in_array($type, ['text', 'image', 'hardBreak', 'horizontalRule'], true));
                $result['content'] = array_map(function ($child) use (&$walk, $depth, $type) {
                    $this->ensure(is_array($child));

                    return $walk($child, $depth + 1, $type);
                }, $node['content']);
            }
            if (in_array($type, ['doc', 'listItem', 'bulletList', 'orderedList', 'blockquote'], true)) {
                $this->ensure(! empty($result['content']));
            }

            return $result;
        };

        return $walk($document);
    }

    private function mark(mixed $mark): array
    {
        $this->ensure(is_array($mark));
        $type = $mark['type'] ?? null;
        $this->ensure(in_array($type, ['bold', 'italic', 'underline', 'strike', 'textStyle', 'link'], true));
        $result = ['type' => $type];
        $attrs = $mark['attrs'] ?? [];
        $this->ensure(is_array($attrs));
        if ($type === 'link') {
            $href = $attrs['href'] ?? '';
            $this->ensure(is_string($href) && strlen($href) <= 2048 && ! str_contains($href, chr(92)) && ! preg_match('/[\x00-\x20]/', $href));
            $this->ensure((preg_match('#^https?://#i', $href) && filter_var($href, FILTER_VALIDATE_URL))
                || (str_starts_with($href, '/') && ! str_starts_with($href, '//'))
                || (str_starts_with($href, 'mailto:') && filter_var(substr($href, 7), FILTER_VALIDATE_EMAIL)));
            $result['attrs'] = ['href' => $href];
        }
        if ($type === 'textStyle') {
            foreach ($attrs as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $normalized = match ($key) {
                    'fontSize' => $this->fontSize($value),
                    'fontFamily' => $this->fontFamily($value),
                    'lineHeight' => is_numeric($value) && (float) $value >= 1 && (float) $value <= 3 ? (string) (float) $value : null,
                    'color', 'backgroundColor' => $this->color($value),
                    default => null,
                };
                $field = match ($key) {
                    'fontSize' => 'Ukuran huruf harus setara 12–48 px. Pilih kembali ukuran huruf melalui editor.',
                    'fontFamily' => 'Jenis huruf belum didukung. Pilih jenis huruf melalui editor.',
                    'lineHeight' => 'Jarak baris harus antara 1 dan 3. Pilih kembali spasi melalui editor.',
                    'color', 'backgroundColor' => 'Format warna teks atau sorotan belum didukung. Pilih kembali warnanya melalui editor.',
                    default => 'Ada format teks yang belum didukung. Gunakan Hapus format pada teks tersebut.',
                };
                $this->ensure($normalized !== null, $field);
                $result['attrs'][$key] = $normalized;
            }
        }

        return $result;
    }

    private function fontSize(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d+(?:\.\d+)?)(px|pt)$/iD', trim($value), $match)) {
            return null;
        }
        $pixels = (float) $match[1] * (strtolower($match[2]) === 'pt' ? 4 / 3 : 1);

        return $pixels >= 12 && $pixels <= 48 ? rtrim(rtrim(number_format($pixels, 2, '.', ''), '0'), '.').'px' : null;
    }

    private function fontFamily(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 250) {
            return null;
        }
        $fonts = ['Arial', 'Georgia', 'Verdana', 'Times New Roman', 'Calibri', 'Cambria', 'Tahoma', 'Trebuchet MS', 'Courier New', 'Segoe UI', 'Inter', 'Roboto', 'sans-serif', 'serif', 'monospace', 'system-ui'];
        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate, " \"'");
            foreach ($fonts as $font) {
                if (strcasecmp($font, $candidate) === 0) {
                    return $font;
                }
            }
        }

        return null;
    }

    private function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $names = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'yellow' => '#ffff00', 'gray' => '#808080', 'grey' => '#808080', 'transparent' => '#00000000'];
        if (isset($names[$value])) {
            return $names[$value];
        }
        if (preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/D', $value)) {
            return strlen($value) <= 5 ? '#'.implode('', array_map(fn ($char) => $char.$char, str_split(substr($value, 1)))) : $value;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0?(?:\.\d+)|[01](?:\.0+)?))?\s*\)$/D', $value, $match)) {
            $channels = [(int) $match[1], (int) $match[2], (int) $match[3]];
            if (max($channels) > 255) {
                return null;
            }
            $hex = sprintf('#%02x%02x%02x', ...$channels);
            $alpha = isset($match[4]) ? (float) $match[4] : 1;

            return $alpha < 1 ? $hex.sprintf('%02x', (int) round($alpha * 255)) : $hex;
        }

        return null;
    }

    public function assetId(mixed $url, Store $store): string
    {
        $this->ensure(is_string($url) && preg_match('#^/article-media/'.preg_quote($store->id, '#').'/([a-f0-9-]{36})$#D', $url, $match));

        return $match[1];
    }

    public function assetIds(array $node, Store $store): array
    {
        $ids = $node['type'] === 'image' ? [$this->assetId($node['attrs']['src'], $store)] : [];
        foreach ($node['content'] ?? [] as $child) {
            $ids = [...$ids, ...$this->assetIds($child, $store)];
        }

        return array_values(array_unique($ids));
    }

    public function text(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return $node['text'];
        }

        return implode(' ', array_map(fn ($child) => $this->text($child), $node['content'] ?? []));
    }

    private function ensure(bool $condition, ?string $message = null): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['content' => $message ?? 'Format isi artikel tidak valid. Periksa teks, tautan, ukuran huruf, dan gambar yang digunakan.']);
        }
    }
}
