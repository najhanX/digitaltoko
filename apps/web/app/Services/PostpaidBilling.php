<?php

namespace App\Services;

use App\Models\ProductMapping;
use App\Models\SupplierProduct;
use Illuminate\Validation\ValidationException;

class PostpaidBilling
{
    public static function adjustment(mixed $value): ?int
    {
        $text = preg_replace('/\s+/u', '', (string) $value);
        $negative = str_starts_with($text, '-');
        $amount = OtomaxParser::amount($negative ? substr($text, 1) : $text);

        return $amount === null ? null : ($negative ? -$amount : $amount);
    }

    public function configured(ProductMapping $mapping, ?SupplierProduct $check = null): bool
    {
        $item = $mapping->item;
        if ($mapping->source === 'digiflazz') {
            return $item?->kind === 'postpaid';
        }
        $settings = $mapping->postpaid;

        return $mapping->source === 'otomax' && $item && is_array($settings)
            && is_string($settings['inquiry_id'] ?? null) && is_int($settings['admin'] ?? null)
            && $settings['admin'] >= 0 && $settings['admin'] <= 100000000
            && $check && $check->id !== $item->id && $check->connection_id === $item->connection_id && $check->available && $check->cost === 0
            && (int) ($check->billing['adjustment'] ?? 0) === 0
            && $check->observed_at?->gte(now()->subHours(max(1, min(168, (int) ($item->connection->settings['catalog_hours'] ?? 24)))));
    }

    public function sourceAdjustment(string $source, ?array $billing): int
    {
        if ($source === 'otomax') {
            $adjustment = self::adjustment($billing['adjustment'] ?? 0);

            return $adjustment ?? 0;
        }

        return -(OtomaxParser::amount($billing['commission'] ?? 0) ?? 0);
    }

    public function storeFee(int $commission, ?string $markup): int
    {
        $commission = abs($commission);

        return app(ProductPricing::class)->apply($commission, $markup) - $commission;
    }

    public function amounts(int $cost, int $selling, int $admin, int $markup, string $name = '', string $period = ''): array
    {
        if ($cost <= 0 || $selling <= 0 || $cost > 100000000 || $selling > 100000000 || $admin < 0 || $admin > $selling || $markup < 0 || $cost + $markup > 100000000) {
            throw ValidationException::withMessages(['inquiry' => 'Rincian tagihan atau modal pemasok tidak valid. Periksa kembali sebelum membayar.']);
        }

        return ['amount' => $cost + $markup, 'cost' => $cost, 'bill' => $selling - $admin,
            'supplier_total' => $selling, 'supplier_admin' => $admin, 'commission' => $selling - $cost,
            'markup' => $markup, 'store_admin' => $cost - $selling + $markup, 'admin' => $admin,
            'customer_name' => mb_substr($name, 0, 150), 'period' => mb_substr($period, 0, 100)];
    }

    public function priceFromSources(array $values, array $candidates, string $mappingId, ?string $markup, ?int $fixedFee = null, string $pricingBasis = 'highest'): array
    {
        $actual = ['mapping_id' => $mappingId, 'cost' => $values['supplier_admin'], 'commission' => $values['cost'] - $values['supplier_total']];
        $candidates = array_values(array_filter($candidates, fn ($candidate) => $candidate['mapping_id'] !== $mappingId));
        $candidates[] = $actual;
        $basis = $actual;
        foreach ($candidates as $candidate) {
            $candidateCost = $candidate['cost'] + ($candidate['commission'] ?? 0);
            $basisCost = $basis['cost'] + ($basis['commission'] ?? 0);
            if ($pricingBasis === 'cheapest' ? $candidateCost < $basisCost : $candidateCost > $basisCost) {
                $basis = $candidate;
            }
        }
        $fee = $fixedFee ?? ($basis['cost'] + ($basis['commission'] ?? 0) + $this->storeFee($basis['commission'] ?? 0, $markup));
        $amount = $values['bill'] + $fee;
        if ($amount < $values['cost'] || $amount > 100000000) {
            throw ValidationException::withMessages(['inquiry' => 'Harga jual tagihan di bawah modal pemasok atau melebihi batas. Periksa pengaturan harga.']);
        }

        return [...$values, 'amount' => $amount, 'markup' => $amount - $values['cost'], 'store_admin' => $fee - $values['supplier_admin']];
    }

    public function publicBreakdown(array $data): ?array
    {
        $bill = $data['bill'] ?? null;
        $service = $data['supplier_admin'] ?? null;
        $store = $data['store_admin'] ?? (is_int($data['amount'] ?? null) && is_int($bill) && is_int($service) ? $data['amount'] - $bill - $service : ($data['markup'] ?? null));
        foreach ([$bill, $service, $store] as $value) {
            if (! is_int($value) || abs($value) > 100000000) {
                return null;
            }
        }
        if ($bill < 0 || $service < 0 || $bill + $service + $store <= 0 || $bill + $service + $store > 100000000) {
            return null;
        }

        return ['bill' => $bill, 'service_admin' => $service, 'store_admin' => $store];
    }

    public function otomax(array $fields, int $adjustment, int $markup): array
    {
        $sn = (string) ($fields['sn'] ?? '');
        $aliases = ['total_tagihan' => 'TTAG', 'tagihan' => 'TAG', 'admin' => '(?:ADM|ADMIN)', 'nama_pelanggan' => 'NAMA', 'periode' => '(?:PERIODE|PTAG)'];
        foreach ($aliases as $field => $key) {
            if (! isset($fields[$field]) && preg_match('~(?:^|/)'.$key.':+([^/]+)~iu', $sn, $match)) {
                $value = trim($match[1]);
                $fields[$field] = in_array($field, ['total_tagihan', 'tagihan', 'admin'], true) ? preg_split('/\s+/', $value)[0] : $value;
            }
        }
        $number = static function (mixed $input): ?int {

            return OtomaxParser::amount($input);
        };
        $total = $number($fields['total_tagihan'] ?? null);
        $admin = $number($fields['admin'] ?? null);
        $bill = $number($fields['tagihan'] ?? null);
        if ($total === null || $admin === null || $bill === null || $bill + $admin !== $total || trim((string) ($fields['nama_pelanggan'] ?? '')) === '') {
            throw ValidationException::withMessages(['inquiry' => 'Jawaban cek tagihan belum lengkap. Atur penangkap nama pelanggan, tagihan, admin, dan total tagihan pada supplier OtomaX.']);
        }

        return $this->amounts($total + $adjustment, $total, $admin, $markup, (string) $fields['nama_pelanggan'], (string) ($fields['periode'] ?? ''));
    }
}
