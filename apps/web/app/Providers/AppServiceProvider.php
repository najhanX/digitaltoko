<?php

namespace App\Providers;

use App\Jobs\SendCustomerNotice;
use App\Models\CustomerNotice;
use App\Models\Store;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        CustomerNotice::created(function (CustomerNotice $notice) {
            $id = $notice->id;
            DB::afterCommit(function () use ($id) {
                try {
                    SendCustomerNotice::dispatch($id);
                } catch (\Throwable $error) {
                    report($error);
                }
            });
        });
        RateLimiter::for('bot-notices', function (Request $request): Limit {
            $token = $request->bearerToken();
            $store = is_string($token) && strlen($token) >= 32 ? Store::where('worker_token_hash', hash('sha256', $token))->value('id') : null;

            return $store ? Limit::perMinute(600)->by('bot-notices:'.$store) : Limit::perMinute(30)->by('bot-notices-invalid:'.$request->ip());
        });
        RateLimiter::for('bot-worker', function (Request $request): Limit {
            $token = $request->bearerToken();
            $store = is_string($token) && strlen($token) >= 32
                ? Store::where('worker_token_hash', hash('sha256', $token))->value('id') : null;

            return $store ? Limit::perMinute(120)->by('bot-store:'.$store)
                : Limit::perMinute(30)->by('bot-invalid:'.$request->ip());
        });
    }
}
