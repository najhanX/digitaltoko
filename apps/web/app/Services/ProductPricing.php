<?php

namespace App\Services;

use App\Models\CatalogProduct;
use App\Models\PricingRule;
use Illuminate\Validation\ValidationException;

class ProductPricing
{
    private array $rules = [];

    public function parse(?string $input): ?array
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        $input = str_replace([',', ' '], ['.', ''], trim($input));
        if (preg_match('/^([0-9]{1,13})$/', $input, $match)) {
            $nominal = (int) $match[1];
            $points = 0;
        } elseif (preg_match('/^([0-9]{1,5}(?:\\.[0-9]{1,2})?)%(?:\\+([0-9]{1,13}))?$/', $input, $match)) {
            $nominal = (int) ($match[2] ?? 0);
            $points = (int) round((float) $match[1] * 100);
        } else {
            throw ValidationException::withMessages(['markup' => 'Gunakan 5000, 5%, atau 5% + 5000.']);
        }
        if ($nominal > 1000000000000 || $points > 1000000) {
            throw ValidationException::withMessages(['markup' => 'Nilai markup terlalu besar.']);
        }

        return ['nominal' => $nominal, 'points' => $points, 'text' => $points ? rtrim(rtrim(number_format($points / 100, 2, '.', ''), '0'), '.').'%'.($nominal ? ' + '.$nominal : '') : (string) $nominal];
    }

    public function apply(int $cost, ?string $markup): int
    {
        $rule = $this->parse($markup) ?? ['nominal' => 0, 'points' => 0];

        return $cost + intdiv($cost * $rule['points'] + 9999, 10000) + $rule['nominal'];
    }

    public function resolve(CatalogProduct $product): array
    {
        $this->rules[$product->store_id] ??= PricingRule::where('store_id', $product->store_id)->get()->keyBy(fn ($rule) => $rule->scope.':'.$rule->key);
        $rules = $this->rules[$product->store_id];
        $category = $rules->get('category:'.mb_strtolower($product->category));
        $service = $rules->get('service:'.mb_strtolower($product->service));

        return ['markup' => $product->markup ?? $category?->markup ?? $service?->markup ?? '0',
            'auto_update' => $product->auto_update ?? $category?->auto_update ?? $service?->auto_update ?? false,
            'level' => $product->markup !== null ? 'product' : ($category?->markup !== null ? 'category' : ($service?->markup !== null ? 'service' : 'default'))];
    }

    public function forget(): void
    {
        $this->rules = [];
    }
}
