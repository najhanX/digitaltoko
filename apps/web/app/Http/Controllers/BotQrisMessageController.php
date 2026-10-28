<?php

namespace App\Http\Controllers;

use App\Models\BotQrisMessage;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerAccess;
use App\Services\StoreRetention;
use App\Support\HanshopIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BotQrisMessageController extends Controller
{
    public function store(Request $request, CustomerAccess $access): JsonResponse
    {
        $store = $access->worker($request);
        $data = $request->validate(['order_id' => 'required|uuid', 'phone' => 'required|string|max:30',
            'kind' => 'sometimes|in:qris,order', 'revision' => 'required_if:kind,order|string|max:40',
            'order_status' => 'required_if:kind,order|string|max:30', 'payment_status' => 'required_if:kind,order|string|max:30',
            'key' => 'required|array:id,remoteJid,fromMe', 'key.id' => ['required', 'string', 'max:200', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'key.remoteJid' => ['required', 'string', 'max:80', 'regex:/^[1-9][0-9]{5,24}@(s\.whatsapp\.net|lid)$/'], 'key.fromMe' => 'required|boolean|accepted']);
        $phone = HanshopIdentity::phone($data['phone']);
        $key = ['id' => $data['key']['id'], 'remoteJid' => $data['key']['remoteJid'], 'fromMe' => true];
        abort_unless(str_ends_with($key['remoteJid'], '@lid') || $key['remoteJid'] === $phone.'@s.whatsapp.net', 422);
        $record = DB::transaction(function () use ($store, $data, $phone, $key) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            StoreRetention::guard($store);
            $order = CustomerOrder::where('store_id', $store->id)->where('phone', $phone)->whereKey($data['order_id'])->lockForUpdate()->firstOrFail();
            $record = BotQrisMessage::firstOrCreate(['store_id' => $store->id, 'key_hash' => hash('sha256', $key['remoteJid']."\0".$key['id'])], ['order_id' => $order->id, 'message_key' => $key, 'kind' => $data['kind'] ?? 'qris', 'revision' => $data['revision'] ?? null, 'order_status' => $data['order_status'] ?? null, 'payment_status' => $data['payment_status'] ?? null]);
            abort_unless($record->order_id === $order->id, 422);

            return $record;
        }, 3);

        return response()->json(['data' => ['id' => $record->id]])->header('Cache-Control', 'private, no-store');
    }

    public function revoked(Request $request, BotQrisMessage $message, CustomerAccess $access): JsonResponse
    {
        $store = $access->worker($request);
        abort_unless($message->store_id === $store->id, 404);
        abort_unless(BotQrisMessage::whereKey($message->id)->readyToRevoke()->exists(), 422);
        if (! $message->revoked_at) {
            $message->update(['revoked_at' => now()]);
        }

        return response()->json(['status' => 'SUCCESS']);
    }
}
