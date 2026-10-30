<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OtomaxProfile;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\OtomaxCatalogSync;
use App\Services\OtomaxParser;
use App\Services\OtomaxWebCatalog;
use App\Services\StoreCatalog;
use App\Services\SupplierSimulator;
use App\Support\StaffPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupplierLabSeeder extends Seeder
{
    public const PASSWORD = 'SupplierLab-2026!';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(SupplierSimulator::class)->assertIsolated();
        if (CustomerOrder::whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending', 'review'])->exists()) {
            throw new \RuntimeException('Selesaikan pesanan lab yang masih berjalan sebelum memasang ulang fixture.');
        }
        $response = Http::timeout(5)->withoutRedirecting()->get(config('suppliers.simulator.control').'/api/response-profile');
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Jalankan versi terbaru supplier-simulator terlebih dahulu (port 4100–4103).');
        }
        $profileConfig = app(OtomaxParser::class)->validate($response->json());
        $owner = User::firstOrCreate(['email' => 'owner@supplier-lab.example.test'], ['name' => 'Owner Supplier Lab', 'password' => Hash::make(self::PASSWORD)]);
        $owner->forceFill(['role' => 'owner', 'verification_status' => 'approved', 'phone' => '6281000000000', 'legal_name' => 'IDENTITAS SINTETIS SUPPLIER LAB', 'email_verified_at' => now(), 'phone_verified_at' => now()])->save();
        $admin = User::firstOrCreate(['email' => 'admin@supplier-lab.example.test'], ['name' => 'Admin Supplier Lab', 'password' => Hash::make(self::PASSWORD)]);
        $admin->forceFill(['role' => 'admin'])->save();
        foreach (['lab-minimum' => 'cheapest', 'lab-maximum' => 'highest'] as $slug => $basis) {
            $store = Store::firstOrCreate(['slug' => $slug], ['owner_id' => $owner->id, 'name' => 'SUPPLIER LAB '.($basis === 'cheapest' ? 'MINIMUM' : 'MAKSIMUM'), 'category' => 'web', 'plan' => 'galaxy', 'status' => 'active', 'sandbox' => true, 'template' => 'aurora', 'expires_at' => now()->addYear(), 'branding' => []]);
            if ($store->owner_id !== $owner->id || ! $store->sandbox) {
                throw new \RuntimeException('Identitas toko lab tidak sesuai; tidak menimpa toko yang ada.');
            }
            StorePaymentSetting::firstOrCreate(['store_id' => $store->id], ['allow_guest' => true, 'closing' => false, 'sandbox' => true]);
            StoreStaff::firstOrCreate(['store_id' => $store->id, 'username' => 'lab-staff'], ['name' => 'Staff Lab', 'email' => 'staff@supplier-lab.example.test', 'password' => Hash::make(self::PASSWORD), 'active' => true, 'permissions' => array_keys(StaffPermissions::labels())]);
            Customer::firstOrCreate(['store_id' => $store->id, 'email' => 'buyer@supplier-lab.example.test'], ['name' => 'Pembeli Simulasi', 'phone' => '6281200000001', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(), 'phone_verified_at' => now(), 'balance' => 0, 'sandbox_balance' => 1000000]);
            foreach (['pulsa' => '500', 'produk digital' => '500', 'pascabayar' => '1000'] as $service => $markup) {
                PricingRule::firstOrCreate(['store_id' => $store->id, 'scope' => 'service', 'key' => $service], ['markup' => $markup, 'auto_update' => true, 'pricing_basis' => $basis]);
            }
            $profile = OtomaxProfile::firstOrCreate(['store_id' => $store->id, 'type' => 'response', 'name' => 'Supplier Lab OtomaX'], ['config' => $profileConfig]);
            foreach (config('suppliers.simulator.origins') as $slot => $origin) {
                $index = array_search($slot, ['a', 'b', 'c'], true) + 1;
                $connection = SupplierConnection::firstOrCreate(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => 'lab-'.$slot], ['name' => 'OtomaX · Supplier '.strtoupper($slot).' LAB', 'enabled' => true, 'response_profile_id' => $profile->id,
                    'credentials' => ['member_id' => 'SIM00'.$index, 'pin' => '1234', 'password' => 'simulator-only', 'callback_token' => Str::random(48)],
                    'settings' => ['base_url' => $origin, 'transaction_path' => '/trx', 'status_path' => '', 'balance_path' => '/balance', 'ticket_path' => '/ticket', 'callback_ips' => ['127.0.0.1'], 'catalog_hours' => 24]]);
                $sync = app(OtomaxCatalogSync::class);
                $connection->refresh();
                $preview = $sync->preview($connection, ['url' => $origin.'/harga.js.php', 'automatic' => false, 'interval' => 5, 'profile' => OtomaxWebCatalog::defaults()], 'supplier-lab');
                $sync->activate($connection->fresh(), $preview, 'supplier-lab', true);
                if ($preview->fresh()->status !== 'completed') {
                    throw new \RuntimeException('Katalog simulator belum tersimpan: '.$preview->fresh()->error);
                }
                foreach (['XLD10', 'XP5', 'ML86', 'BYRPLN20', 'BYRPDAM1'] as $sku) {
                    $item = SupplierProduct::where('connection_id', $connection->id)->where('sku', $sku)->firstOrFail();
                    $postpaid = str_starts_with($sku, 'BYR');
                    $product = CatalogProduct::firstOrCreate(['store_id' => $store->id, 'code' => 'LAB-'.$sku], ['name' => $item->name, 'kind' => $postpaid ? 'postpaid' : 'ppob', 'service' => $postpaid ? 'Pascabayar' : ($sku === 'ML86' ? 'Produk digital' : 'Pulsa'), 'category' => $item->category, 'sale_price' => 0, 'active' => true, 'auto_update' => true]);
                    $check = $postpaid ? SupplierProduct::where('connection_id', $connection->id)->where('sku', str_replace('BYR', 'CEK', $sku))->firstOrFail() : null;
                    ProductMapping::firstOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$item->id], ['store_id' => $store->id, 'source' => 'otomax', 'supplier_product_id' => $item->id, 'active' => true,
                        'postpaid' => $postpaid ? ['inquiry_id' => $check->id, 'admin' => $sku === 'BYRPLN20' ? 2000 : 3500] : null]);
                }
            }
            app(StoreCatalog::class)->reprice($store);
        }
        $links = Store::whereIn('slug', config('suppliers.simulator.slugs'))->get()->map(fn ($store) => ['name' => $store->name, 'storefront' => 'http://'.$store->slug.'.localhost:8012', 'staff' => 'http://localhost:8012/staff/'.$store->id.'/login']);
        file_put_contents(storage_path('app/private/supplier-lab-links.json'), json_encode(['stores' => $links, 'admin' => 'http://localhost:8012/admin/login'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->command?->info('Dua toko Supplier Lab dibuat dengan tiga parsing OtomaX, katalog, staff, pembeli dan saldo uji.');
    }
}
