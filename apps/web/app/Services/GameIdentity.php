<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\Store;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameIdentity
{
    public const FORMATS = ['auto', 'concat', 'parentheses', 'pipe', 'space', 'id'];

    public function games(): array
    {
        $games = [
            'ml' => ['Mobile Legends', 'numeric', 'Zone ID', []],
            'mcgg' => ['Magic Chess: Go Go', 'numeric', 'Zone ID', []],
            'ff' => ['Free Fire', 'numeric', null, []],
            'aov' => ['Arena of Valor', 'numeric', null, []],
            'codm' => ['Call of Duty Mobile', 'numeric', null, []],
            'gi' => ['Genshin Impact', 'numeric', null, []],
            'hi' => ['Honkai Impact 3rd', 'numeric', null, []],
            'hsr' => ['Honkai: Star Rail', 'numeric', null, []],
            'ld' => ['Love and Deepspace', 'numeric', null, []],
            'pb' => ['Point Blank', 'text', null, []],
            'sm' => ['Sausage Man', 'text', null, []],
            'sus' => ['Super Sus', 'numeric', null, []],
            'valo' => ['Valorant', 'riot', null, []],
            'zzz' => ['Zenless Zone Zero', 'numeric', null, []],
            'pgr' => ['Punishing: Gray Raven', 'numeric', 'Server', ['AP', 'EU', 'NA']],
            'la' => ['LifeAfter', 'numeric', 'Server', ['MiskaTown', 'SandCastle', 'MouthSwamp', 'RedwoodTown', 'Obelisk', 'NewLand', 'ChaosOutpost', 'IronStride', 'CrystalthornSea', 'FallForest', 'MountSnow', 'NancyCity', 'CharlesTown', 'SnowHighlands', 'Santopany', 'LevinCity', 'MileStone', 'ChaosCity', 'TwinIslands', 'HopeWall', 'LabyrinthSea']],
        ];
        $result = [];
        foreach ($games as $key => [$name, $type, $server, $options]) {
            $result[$key] = ['game' => $key, 'name' => $name, 'id_type' => $type, 'id_label' => $type === 'riot' ? 'Riot ID + tag' : ($key === 'pb' ? 'Zepetto ID' : 'User ID'),
                'server_label' => $server, 'server_options' => $options, 'default_format' => in_array($key, ['ml', 'mcgg'], true) ? 'concat' : 'id'];
        }

        return $result;
    }

    public function profile(Store $store, CatalogCategory $category): ?array
    {
        $destination = $store->branding['category_destinations'][$category->id] ?? [];
        if (! in_array($destination['type'] ?? 'auto', ['auto', 'game'], true)) {
            return null;
        }
        $setting = $store->branding['category_games'][$category->id] ?? [];
        $key = $setting['game'] ?? 'auto';
        if ($key === 'auto') {
            $name = preg_replace('/[^a-z0-9]/', '', strtolower($category->name));
            $aliases = ['mobilelegends' => 'ml', 'mobilelegendsbangbang' => 'ml', 'mlbb' => 'ml', 'ml' => 'ml', 'magicchessgogo' => 'mcgg', 'mcgg' => 'mcgg', 'freefire' => 'ff', 'freefiremax' => 'ff', 'ff' => 'ff', 'arenaofvalor' => 'aov', 'aov' => 'aov', 'callofdutymobile' => 'codm', 'codm' => 'codm', 'genshinimpact' => 'gi', 'honkaiimpact3rd' => 'hi', 'honkaistarrail' => 'hsr', 'loveanddeepspace' => 'ld', 'pointblank' => 'pb', 'sausageman' => 'sm', 'supersus' => 'sus', 'valorant' => 'valo', 'zenlesszonezero' => 'zzz', 'punishinggrayraven' => 'pgr', 'lifeafter' => 'la'];
            $key = $aliases[$name] ?? 'none';
        }
        $profile = $this->games()[$key] ?? null;
        if (! $profile) {
            return null;
        }

        $format = $setting['destination_format'] ?? 'auto';

        return [...$profile, 'category_id' => $category->id, 'destination_format' => $format === 'auto' ? $profile['default_format'] : $format];
    }

    public function fields(array $profile, array $input): array
    {
        $data = Validator::make($input, ['id' => ['required', 'string', 'max:64', $profile['id_type'] === 'numeric' ? 'regex:/^[0-9]{4,24}$/' : ($profile['id_type'] === 'riot' ? 'regex:/^[^\x00-\x1f\x7f#]{2,40}#[a-zA-Z0-9]{2,10}$/u' : 'regex:/^[a-zA-Z0-9_.-]{3,64}$/')],
            'server' => $profile['server_label'] ? ['required', 'string', count($profile['server_options']) ? Rule::in($profile['server_options']) : 'regex:/^[0-9]{3,8}$/'] : ['nullable', 'string', 'max:0'],
        ], ['id.regex' => 'Format ID game belum benar.', 'server.required' => 'Zona/server wajib diisi.', 'server.regex' => 'Zona harus berisi 3–8 digit.'])->validate();

        return ['id' => trim($data['id']), 'server' => $data['server'] ?? ''];
    }

    public function destination(array $profile, string $id, string $server): string
    {
        if (! $profile['server_label'] || $profile['destination_format'] === 'id') {
            return $id;
        }

        return match ($profile['destination_format']) {
            'parentheses' => $id.'('.$server.')',
            'pipe' => $id.'|'.$server,
            'space' => $id.' '.$server,
            default => $id.$server,
        };
    }

    public function lookup(Store $store, CatalogCategory $category, array $input): array
    {
        $profile = $this->profile($store, $category);
        abort_unless($profile, 422, 'Pengecekan ID belum tersedia untuk kategori ini.');
        $fields = $this->fields($profile, $input);
        $base = rtrim((string) config('services.game_identity.url'), '/');
        $hash = hash_hmac('sha256', json_encode([$base, $profile['game'], $fields]), (string) config('app.key'));
        $key = 'game-identity:'.$hash;
        try {
            $account = Cache::lock($key.':lock', 10)->block(2, function () use ($key, $profile, $fields, $base) {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    return $cached;
                }
                abort_unless(RateLimiter::attempt('game-identity:upstream', 60, fn () => true, 60), 503, 'Layanan cek ID sedang sibuk. Coba beberapa saat lagi.');
                try {
                    $response = Http::acceptJson()->connectTimeout(3)->timeout(6)->withOptions(['allow_redirects' => false])
                        ->post($base.'/'.$profile['game'].'?decode=false', $fields);
                } catch (\Throwable) {
                    abort(503, 'Layanan cek ID belum dapat dihubungi. Silakan cek ulang.');
                }
                abort_unless($response->successful(), 503, 'Layanan cek ID sedang tidak tersedia. Silakan cek ulang.');
                $body = $response->json();
                abort_unless(is_array($body) && is_bool($body['success'] ?? null), 503, 'Respons layanan cek ID belum dapat dibaca.');
                if (! $body['success']) {
                    Cache::put($key, ['valid' => false], 15);

                    return ['valid' => false];
                }
                $name = is_string($body['name'] ?? null) ? trim(rawurldecode($body['name'])) : '';
                abort_unless($name !== '' && mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 160 && ! preg_match('/[\x00-\x1f\x7f]/u', $name), 503, 'Nickname belum dapat dibaca dari layanan cek ID.');
                if (isset($body['id']) && $profile['id_type'] === 'numeric') {
                    abort_unless(is_scalar($body['id']) && ltrim((string) $body['id'], '0') === ltrim($fields['id'], '0'), 503, 'ID pada respons layanan tidak sesuai. Silakan cek ulang.');
                }
                if (isset($body['server']) && in_array($profile['game'], ['ml', 'mcgg'], true)) {
                    abort_unless(is_scalar($body['server']) && ltrim((string) $body['server'], '0') === ltrim($fields['server'], '0'), 503, 'Zona pada respons layanan tidak sesuai. Silakan cek ulang.');
                }
                $account = ['valid' => true, 'nickname' => $name];
                Cache::put($key, $account, 180);

                return $account;
            });
        } catch (LockTimeoutException) {
            abort(503, 'Pengecekan ID masih berjalan. Silakan cek ulang.');
        }
        if (! $account['valid']) {
            throw ValidationException::withMessages(['id' => 'Akun tidak ditemukan. Periksa kembali ID dan zona/server.']);
        }
        $expires = now()->addMinutes(10)->timestamp;
        $proof = ['store' => $store->id, 'category' => $category->id, 'game' => $profile['game'], ...$fields, 'nickname' => $account['nickname'], 'expires' => $expires];

        return [...$fields, 'nickname' => $account['nickname'], 'destination' => $this->destination($profile, $fields['id'], $fields['server']),
            'verification' => Crypt::encryptString(json_encode($proof)), 'expires_at' => $expires];
    }

    public function checkout(Store $store, CatalogProduct $product, array $input): ?array
    {
        $profile = $this->profile($store, $product->taxonomyCategory);
        if (! $profile) {
            return null;
        }
        try {
            $proof = json_decode(Crypt::decryptString((string) ($input['game_verification'] ?? '')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['game_verification' => 'Periksa ID game hingga nickname ditemukan sebelum memesan.']);
        }
        if (! is_array($proof) || ($proof['store'] ?? null) !== $store->id || ($proof['category'] ?? null) !== $product->category_id
            || ($proof['game'] ?? null) !== $profile['game'] || ($proof['expires'] ?? 0) <= now()->timestamp
            || $this->destination($profile, $proof['id'] ?? '', $proof['server'] ?? '') !== ($input['destination'] ?? '')) {
            throw ValidationException::withMessages(['game_verification' => 'Verifikasi game tidak sesuai atau sudah kedaluwarsa. Cek ulang ID game.']);
        }

        return ['game' => $profile['name'], 'id' => $proof['id'], 'server' => $proof['server'], 'nickname' => $proof['nickname']];
    }
}
