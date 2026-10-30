<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerOrder;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\OtomaxCatalogSync;
use App\Services\OtomaxWebCatalog;
use App\Services\StoreCatalog;
use App\Services\SupplierSimulator;
use Database\Seeders\SupplierLabSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('supplier-lab {action=info : info, sync, digiflazz, exercise, resume, retry, export} {--store=minimum} {--preset=normal} {--kind=prepaid} {--payment=wallet} {--order=} {--destination=} {--fallback=} {--no-reset}')]
#[Description('Operasikan database/toko Supplier Lab terisolasi tanpa supplier atau pembayaran produksi')]
class SupplierLab extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        app(SupplierSimulator::class)->assertIsolated();
        $action = $this->argument('action');
        if ($action === 'digiflazz') {
            $this->attachDigiflazz();

            return self::SUCCESS;
        }
        if (in_array($action, ['resume', 'retry'], true)) {
            $order = CustomerOrder::where('number', $this->option('order'))->firstOrFail();
            if (! app(SupplierSimulator::class)->store($order->store)) {
                throw new \RuntimeException('Pesanan bukan milik toko lab.');
            }
            $engine = app(CustomerFulfillment::class);
            $action === 'retry' ? $engine->retrySupplier($order) : $engine->run($order);
            $this->info($order->number.': '.$order->fresh()->status);

            return self::SUCCESS;
        }
        if ($action === 'info') {
            $this->table(['Toko', 'Storefront', 'Panel staff'], Store::whereIn('slug', config('suppliers.simulator.slugs'))->get()->map(fn ($store) => [$store->name, 'http://'.$store->slug.'.localhost:8012', config('app.url').'/staff/'.$store->id.'/login']));
            $this->info('Staff: lab-staff | Pembeli: buyer@supplier-lab.example.test | Admin: admin@supplier-lab.example.test');
            $this->info('Password sintetis: '.SupplierLabSeeder::PASSWORD);
            $this->info('Saldo pembeli yang dipakai hanya sandbox_balance. Simulator: http://127.0.0.1:4100');

            return self::SUCCESS;
        }
        if ($action === 'sync') {
            $this->sync();

            return self::SUCCESS;
        }
        if ($action === 'export') {
            $path = storage_path('app/private/supplier-lab-orders.json');
            $rows = CustomerOrder::with('attempts')->get()->map(fn ($order) => ['number' => $order->number, 'store_id' => $order->store_id, 'status' => $order->status, 'payment_status' => $order->payment_status, 'amount' => $order->amount,
                'attempts' => $order->attempts->map(fn ($a) => [...$a->only(['position', 'source', 'reference', 'status', 'cost', 'message']), 'supplier_response' => $a->request_snapshot['supplier_response'] ?? null])]);
            file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info($path);

            return self::SUCCESS;
        }
        if ($action !== 'exercise') {
            $this->error('Pilih info, sync, exercise, atau export.');

            return self::INVALID;
        }
        $storeName = $this->option('store');
        $kind = $this->option('kind');
        $payment = $this->option('payment');
        if (! in_array($storeName, ['minimum', 'maximum'], true) || ! in_array($kind, ['prepaid', 'hybrid', 'pln', 'pdam'], true) || ! in_array($payment, ['wallet', 'gateway'], true)) {
            $this->error('Pilihan store/kind/payment tidak valid.');

            return self::INVALID;
        }
        $noReset = (bool) $this->option('no-reset');
        if ($noReset && ($kind !== 'hybrid' || $this->option('fallback') || $this->option('preset') !== 'normal')) {
            $this->error('--no-reset hanya untuk hybrid dengan konfigurasi supplier saat ini; jangan gabungkan dengan --fallback/preset lain.');

            return self::INVALID;
        }
        if (! $noReset && (CustomerOrder::whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending', 'review'])->exists() || CustomerInquiry::where('status', 'pending')->where('expires_at', '>', now())->exists())) {
            $this->error('Masih ada pesanan/inquiry lab berjalan atau review. Selesaikan dari panel sebelum reset supplier untuk run berikutnya.');

            return self::FAILURE;
        }
        if ($kind === 'hybrid' && (! config('suppliers.simulator.digiflazz_development') || ! in_array($this->option('destination'), ['087800001230', '087800001232', '087800001233', '087800001234'], true))) {
            $this->error('Aktifkan Digiflazz development lab dan pilih --destination dari empat nomor testcase resmi.');

            return self::INVALID;
        }
        if ($this->option('fallback') && ($kind !== 'hybrid' || ! in_array($this->option('fallback'), ['a', 'b', 'c', 'none'], true))) {
            $this->error('--fallback hanya untuk hybrid: a, b, c atau none.');

            return self::INVALID;
        }
        if ($kind === 'hybrid' && ! CatalogProduct::whereIn('store_id', Store::where('slug', 'lab-'.$storeName)->select('id'))->where('code', 'LAB-HYBRID-XLD10')->exists()) {
            $this->error('Jalankan supplier-lab digiflazz dahulu.');

            return self::INVALID;
        }
        $run = 'lab-'.Str::lower(Str::random(12));
        if (! $noReset) {
            $reset = Http::timeout(5)->post(config('suppliers.simulator.control').'/api/reset', ['preset' => $this->option('preset'), 'run' => $run, 'confirm' => 'RESET']);
            if (! $reset->successful()) {
                $this->error('Reset simulator ditolak: '.($reset->json('error') ?? $reset->status()));

                return self::FAILURE;
            }
        }
        if ($kind === 'hybrid' && ! $noReset) {
            Http::timeout(5)->patch(config('suppliers.simulator.control').'/api/suppliers/a/products/XLD10', ['cost' => 10100, 'catalogCost' => 10100])->throw();
            if ($this->option('fallback')) {
                foreach (['a', 'b', 'c'] as $slot) {
                    Http::timeout(5)->patch(config('suppliers.simulator.control').'/api/suppliers/'.$slot, ['behavior' => $slot === $this->option('fallback') ? 'success' : 'failed'])->throw();
                }
            }
        }
        if (! $noReset) {
            $this->sync();
        }
        $store = Store::where('slug', 'lab-'.$storeName)->firstOrFail();
        $customer = Customer::where('store_id', $store->id)->where('email', 'buyer@supplier-lab.example.test')->firstOrFail();
        $product = CatalogProduct::where('store_id', $store->id)->where('code', 'LAB-'.(['prepaid' => 'XLD10', 'hybrid' => 'HYBRID-XLD10', 'pln' => 'BYRPLN20', 'pdam' => 'BYRPDAM1'][$kind]))->firstOrFail();
        if ($kind === 'hybrid') {
            SupplierProduct::where('store_id', $store->id)->where('connection_id', SupplierConnection::where('store_id', $store->id)->where('kind', 'digiflazz')->value('id'))->update(['observed_at' => now()]);
            SupplierConnection::where('store_id', $store->id)->where('kind', 'digiflazz')->update(['synced_at' => now()]);
            app(StoreCatalog::class)->reprice($store);
        }
        $destination = $kind === 'hybrid' ? $this->option('destination') : ['prepaid' => '081200000001', 'pln' => '530000000001', 'pdam' => '60038680'][$kind];
        $inquiry = in_array($kind, ['prepaid', 'hybrid'], true) ? null : app(CustomerInquiryService::class)->quote($store, $product->id, $destination, $customer->phone, $run.'-check');
        $order = app(CustomerCommerce::class)->begin($store, ['purpose' => 'product', 'product_id' => $product->id, 'payment' => $payment, 'destination' => $destination, 'phone' => $customer->phone, 'idempotency_key' => $run, 'inquiry_id' => $inquiry?->id], $customer);
        if ($payment === 'gateway') {
            app(CustomerPayments::class)->start($order);
            app(CustomerPayments::class)->simulate($order);
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline && in_array($order->fresh()->status, ['paid', 'processing', 'pending'], true)) {
            usleep(250000);
        }
        $order->refresh();
        $this->table(['Pesanan', 'Status', 'Bayar', 'Harga pelanggan', 'Saldo uji'], [[$order->number, $order->status, $order->payment_status, $order->amount, $customer->fresh()->sandbox_balance]]);
        $this->table(['Urutan', 'RefID supplier', 'Status', 'Modal'], $order->attempts()->orderBy('position')->get()->map(fn ($attempt) => [$attempt->position, $attempt->reference, $attempt->status, $attempt->cost]));
        $this->info('Bukti pesanan: http://'.$store->slug.'.localhost:8012/orders/'.$order->id.'?access='.app(CustomerCommerce::class)->token($order));

        return self::SUCCESS;
    }

    private function attachDigiflazz(): void
    {
        if (! config('suppliers.simulator.digiflazz_development')) {
            throw new \RuntimeException('Aktifkan SUPPLIER_SIMULATOR_DIGIFLAZZ_DEVELOPMENT hanya pada .env.supplier-lab.');
        }
        if (CustomerOrder::whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending', 'review'])->exists()) {
            throw new \RuntimeException('Selesaikan pesanan lab sebelum memasang koneksi development.');
        }
        $credentials = json_decode(file_get_contents(storage_path('app/private/supplier-lab-digiflazz.json')), true, 512, JSON_THROW_ON_ERROR);
        if (empty($credentials['username']) || empty($credentials['development_key']) || isset($credentials['api_key'])) {
            throw new \RuntimeException('Berkas privat lab harus berisi development key saja.');
        }
        foreach (Store::whereIn('slug', config('suppliers.simulator.slugs'))->get() as $store) {
            if (! app(SupplierSimulator::class)->store($store)) {
                throw new \RuntimeException('Toko harus sandbox lab.');
            }
            $connection = SupplierConnection::updateOrCreate(['store_id' => $store->id, 'kind' => 'digiflazz', 'slot' => 'lab-digiflazz'], ['name' => 'Digiflazz Development langsung', 'credentials' => $credentials, 'enabled' => true, 'synced_at' => now(), 'catalog_modes' => ['prepaid']]);
            $item = SupplierProduct::updateOrCreate(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => 'xld10', 'mode' => 'prepaid'], ['name' => 'XL 10.000 · Testcase resmi Digiflazz', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'quantity' => null, 'observed_at' => now()]);
            $product = CatalogProduct::firstOrCreate(['store_id' => $store->id, 'code' => 'LAB-HYBRID-XLD10'], ['name' => 'XL 10.000 · Digiflazz dev + A/B/C', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'sale_price' => 0, 'active' => true, 'auto_update' => true]);
            foreach (SupplierProduct::where('store_id', $store->id)->whereIn('sku', ['XLD10', 'xld10'])->whereIn('connection_id', SupplierConnection::where('store_id', $store->id)->whereIn('kind', ['digiflazz', 'otomax'])->pluck('id'))->get() as $source) {
                ProductMapping::firstOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$source->id], ['store_id' => $store->id, 'source' => $source->connection->kind, 'supplier_product_id' => $source->id, 'active' => true]);
            }
            app(StoreCatalog::class)->reprice($store);
        }
        unlink(storage_path('app/private/supplier-lab-digiflazz.json'));
        $this->info('Produk LAB-HYBRID-XLD10 dipasang pada dua toko dengan Digiflazz development dan A/B/C. Harga Digiflazz Rp10.000 adalah fixture testcase resmi, bukan sinkronisasi katalog produksi.');
    }

    private function sync(): void
    {
        foreach (SupplierConnection::with('store')->where('kind', 'otomax')->get() as $connection) {
            if (! app(SupplierSimulator::class)->connection($connection)) {
                continue;
            }
            $sync = app(OtomaxCatalogSync::class);
            $url = $connection->settings['base_url'].'/harga.js.php';
            $profile = OtomaxWebCatalog::defaults();
            $cacheKey = 'otomax:url:v1:'.hash_hmac('sha256', json_encode([$url, $profile]), (string) config('app.key'));
            Cache::store('file')->forget($cacheKey);
            Cache::store('file')->forget($cacheKey.':cooldown');
            $preview = $sync->preview($connection, ['url' => $url, 'automatic' => false, 'interval' => 5, 'profile' => $profile], 'supplier-lab-sync');
            $sync->activate($connection->fresh(), $preview, 'supplier-lab-sync', true);
            if ($preview->fresh()->status !== 'completed') {
                throw new \RuntimeException('Sinkronisasi lab gagal: '.$preview->fresh()->error);
            }
        }
        $this->info('Katalog enam koneksi lab disinkronkan.');
    }
}
