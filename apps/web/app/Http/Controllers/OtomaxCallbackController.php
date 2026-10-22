<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\OrderAttempt;
use App\Models\OtomaxEvent;
use App\Models\SupplierConnection;
use App\Services\CustomerFulfillment;
use App\Services\OtomaxClient;
use App\Services\OtomaxParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtomaxCallbackController extends Controller
{
    public function __invoke(Request $request, SupplierConnection $connection, string $token): JsonResponse
    {
        abort_unless($connection->kind === 'otomax', 404);
        $secret = $connection->credentials['callback_token'] ?? '';
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, $token), 401);
        abort_unless(in_array($request->ip(), $connection->settings['callback_ips'] ?? [], true), 401, 'IP pengirim tidak diizinkan.');
        abort_if(strlen($request->getContent()) > 64000 || strlen($request->server('QUERY_STRING', '')) > 64000, 413);
        $input = $request->all();
        abort_if(count($input) > 20, 422);
        foreach ($input as $value) {
            abort_unless(is_scalar($value) || $value === null, 422);
            abort_if(strlen((string) $value) > 20000, 422);
        }
        $parser = app(OtomaxParser::class);
        $current = $parser->parse(app(OtomaxClient::class)->profile($connection), 'callback', $input);
        $references = array_values(array_unique(array_filter([($current['fields']['refid'] ?? null), ...array_values($input)], fn ($v) => is_string($v) && strlen($v) <= 100)));
        $attempts = OrderAttempt::where('source', 'otomax')->whereIn('reference', $references)->whereHas('order', fn ($q) => $q->where('store_id', $connection->store_id))->get();
        $attempt = $attempts->first(fn ($a) => ($a->request_snapshot['connection_id'] ?? null) === $connection->id);
        if (! $attempt) {
            return response()->json(['status' => 'IGNORED']);
        }
        $order = CustomerOrder::findOrFail($attempt->order_id);
        $snapshot = $attempt->request_snapshot;
        $parsed = $parser->parse($snapshot['otomax']['profile'], 'callback', $input);
        $message = (string) ($input[$snapshot['otomax']['profile']['message_field'] ?? 'message'] ?? '');
        $attempt->recordSupplierResponse($message, $parsed['error']);
        $f = $parsed['fields'];
        if ($parsed['error']) {
            OtomaxEvent::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'operation' => 'callback', 'status' => 'unmatched', 'reference' => $attempt->reference, 'data' => ['message' => $message, 'parsed' => $parsed]]);

            return response()->json(['status' => 'REVIEW']);
        }
        abort_unless(($f['refid'] ?? '') === $attempt->reference, 422, 'RefID tidak sesuai.');
        abort_unless(! isset($f['tujuan']) || $f['tujuan'] === $order->destination, 422, 'Tujuan tidak sesuai.');
        abort_unless(! isset($f['kode_produk']) || strtoupper($f['kode_produk']) === strtoupper($snapshot['sku']), 422, 'Produk tidak sesuai.');
        app(CustomerFulfillment::class)->otomaxCallback($order, $attempt, $parsed);
        OtomaxEvent::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'operation' => 'callback', 'status' => $parsed['error'] ? 'unmatched' : $parsed['status'], 'reference' => $attempt->reference, 'data' => ['message' => $message, 'parsed' => $parsed]]);

        return response()->json(['status' => 'SUCCESS']);
    }
}
