<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class PackageCatalog
{
    public static function all(): array
    {
        return config('package_catalog', []);
    }

    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function name(string $category, string $plan): string
    {
        return collect(self::all())->first(fn ($item) => $item['category'] === $category)['name'] ?? match ($category) {
            'bot' => 'Bot WhatsApp (paket lama)', 'web' => 'Web Topup (paket lama)', default => 'Paket toko'
        };
    }

    public static function offerName(string $category, string $plan, int $days): string
    {
        return self::name($category, $plan).' · '.$days.' hari';
    }

    public static function ordered(Builder $query): Builder
    {
        $clauses = [];
        $bindings = [];
        foreach (self::all() as $rank => $package) {
            $clauses[] = 'WHEN category = ? AND plan = ? THEN '.(int) $rank;
            array_push($bindings, $package['category'], $package['plan']);
        }

        return $query->orderByRaw('CASE '.implode(' ', $clauses).' ELSE 99 END', $bindings);
    }

    public static function filter(Builder $query, string $key): Builder
    {
        $package = collect(self::all())->firstWhere('key', $key);

        return $package ? $query->where('category', $package['category']) : $query;
    }
}
