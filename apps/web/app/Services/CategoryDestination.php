<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\Store;
use Illuminate\Support\Facades\Validator;

class CategoryDestination
{
    public const TYPES = ['auto', 'game', 'premium', 'phone', 'pln', 'other'];

    public function profile(Store $store, CatalogCategory $category, ?string $kind = null): array
    {
        $setting = $store->branding['category_destinations'][$category->id] ?? [];
        $game = app(GameIdentity::class)->profile($store, $category);
        $type = $setting['type'] ?? 'auto';
        if ($type === 'auto') {
            $name = mb_strtolower($category->service->name.' '.$category->name);
            $serviceName = mb_strtolower($category->service->name);
            $phonePattern = '/pulsa|\bdata\b|\bkuota\b|paket.*nelpon|voucher.*data|internet|telepon/';
            $premiumPattern = '/premium|aplikasi|\bapk\b/';
            $type = match (true) {
                $game !== null, preg_match('/game|gaming|voucher game/', $name) === 1 => 'game',
                preg_match('/pln|listrik|meteran/', $name) === 1 => 'pln',
                $kind === 'postpaid' => 'other',
                preg_match($premiumPattern, $serviceName) === 1 => 'premium',
                preg_match($phonePattern, $serviceName) === 1 => 'phone',
                $kind === 'premium', preg_match($premiumPattern, $name) === 1 => 'premium',
                preg_match($phonePattern, $name) === 1 => 'phone',
                default => 'other',
            };
        }
        $columns = $type === 'game' ? ($game ? ($game['server_label'] ? 2 : 1) : (int) (($setting['columns'] ?? 'auto') === 'auto' ? 1 : $setting['columns'])) : 1;

        return ['type' => $type, 'columns' => $columns, 'category_id' => $category->id,
            'format' => $game && $type === 'game' ? $game['destination_format'] : ($store->branding['category_games'][$category->id]['destination_format'] ?? 'concat'),
            'label' => match ($type) {
                'game' => 'ID game / tujuan', 'phone' => 'Nomor HP tujuan', 'pln' => 'ID PLN / nomor meteran', default => 'Tujuan / ID pelanggan',
            },
        ];
    }

    public function destination(Store $store, CatalogProduct $product, array $input, string $phone): string
    {
        $profile = $this->profile($store, $product->taxonomyCategory, $product->kind);
        if ($profile['type'] === 'premium') {
            return $phone;
        }
        $destination = trim((string) ($input['destination'] ?? ''));
        if ($profile['type'] === 'game' && ! app(GameIdentity::class)->profile($store, $product->taxonomyCategory)) {
            $fields = Validator::make($input, ['destination_id' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
                'destination_server' => [$profile['columns'] === 2 ? 'required' : 'nullable', 'string', 'max:64', 'not_regex:/[\x00-\x1f\x7f]/'],
            ])->validate();
            $destination = app(GameIdentity::class)->destination(['server_label' => $profile['columns'] === 2 ? 'Server' : null, 'destination_format' => $profile['format']], trim($fields['destination_id']), trim($fields['destination_server'] ?? ''));
        }
        if ($profile['type'] === 'phone') {
            $destination = ltrim(preg_replace('/[\s()-]/', '', $destination), '+');
        }
        $rule = match ($profile['type']) {
            'phone' => 'regex:/^[0-9]{8,16}$/', 'pln' => 'regex:/^[0-9]{8,20}$/', default => 'not_regex:/[\x00-\x1f\x7f]/',
        };
        Validator::make(['destination' => $destination], ['destination' => ['required', 'string', 'max:255', $rule]], [
            'destination.regex' => $profile['type'] === 'phone' ? 'Nomor HP tujuan harus berisi 8–16 digit.' : 'ID PLN / nomor meteran harus berisi 8–20 digit.',
        ])->validate();

        return $destination;
    }
}
