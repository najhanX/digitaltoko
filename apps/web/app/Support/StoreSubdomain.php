<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class StoreSubdomain
{
    public static function normalize(mixed $slug): mixed
    {
        return is_string($slug) ? strtolower(trim($slug)) : $slug;
    }

    public static function rules(): array
    {
        return ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::notIn(config('platform.reserved_slugs')), Rule::unique('stores', 'slug')];
    }

    public static function messages(): array
    {
        return ['slug.required' => 'Masukkan subdomain toko.', 'slug.string' => 'Masukkan subdomain yang valid.',
            'slug.min' => 'Subdomain minimal 3 karakter.', 'slug.max' => 'Subdomain maksimal 40 karakter.',
            'slug.regex' => 'Gunakan huruf kecil, angka, dan tanda hubung di antara kata.',
            'slug.not_in' => 'Subdomain ini digunakan untuk layanan platform. Pilih yang lain.',
            'slug.unique' => 'Subdomain sudah digunakan. Coba nama lain.'];
    }
}
