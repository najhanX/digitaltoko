<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDigiflazzCallback;
use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\SupplierConnection;
use App\Services\CustomerFulfillment;
use App\Services\StoreRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DigiflazzWebhookController extends Controller
{
    public function __invoke(Request $request, CustomerFulfillment $fulfillment): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 64000, 413);
        if ($request->header('X-Digiflazz-Event') === 'ping') {
            return response()->json(['status' => 'SUCCESS', 'event' => 'ping']);
        }
        $reference = $request->input('data.ref_id');
        abort_unless(is_string($reference) && strlen($reference) <= 100, 422);
        $attempt = OrderAttempt::where('reference', $reference)->where('source', 'digiflazz')->first();
        if (! $attempt) {
            return response()->json(['status' => 'IGNORED']);
        }
        $snapshot = $attempt->request_snapshot;
        $order = CustomerOrder::findOrFail($attempt->order_id);
        $connection = SupplierConnection::whereKey($snapshot['connection_id'] ?? null)->where('store_id', $order->store_id)->where('kind', 'digiflazz')->first();
        $secret = $connection?->credentials['webhook_secret'] ?? '';
        abort_unless(is_string($secret) && strlen($secret) >= 16 && hash_equals('sha1='.hash_hmac('sha1', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature')), 401, 'Signature webhook Digiflazz tidak cocok. Samakan Secret ID Digiflazz dengan secret webhook tersimpan pada koneksi toko.');
        $data = $request->validate(['data' => 'required|array', 'data.ref_id' => 'required|string', 'data.buyer_sku_code' => 'required|string', 'data.customer_no' => 'required|string', 'data.status' => 'required|in:Sukses,Gagal,Pending', 'data.rc' => 'required|string|max:4', 'data.price' => 'nullable|numeric|min:0', 'data.sn' => 'nullable|string|max:20000']);
        abort_unless($data['data']['buyer_sku_code'] === ($snapshot['sku'] ?? null) && $data['data']['customer_no'] === $order->destination, 422);
        abort_unless((bool) ($snapshot['testing'] ?? false) === $order->is_sandbox, 409);
        try {
            $fulfillment->digiflazzCallback($order, $attempt, $data['data']);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 503) {
                throw $error;
            }
            StoreRetention::guard($order->store->fresh());
            ProcessDigiflazzCallback::dispatch($attempt->id, $data['data']);
        }

        return response()->json(['status' => 'SUCCESS']);
    }
}
