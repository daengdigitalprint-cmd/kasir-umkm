<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Observers\CustomerObserver;
use App\Observers\ProductObserver;
use App\Observers\TransactionObserver;
use Illuminate\Support\Facades\URL;
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
        // Observer untuk invalidasi cache otomatis saat data master/laporan berubah
        Transaction::observe(TransactionObserver::class);
        Product::observe(ProductObserver::class);
        Customer::observe(CustomerObserver::class);

        // Force HTTPS in production
        if (app()->environment('production')) {
            URL::forceScheme('https');
            // URL yang dibuat (canonical, og:url, sitemap, link email) selalu memakai APP_URL,
            // bukan host yang sedang diakses (mis. www.).
            URL::forceRootUrl((string) config('app.url'));
        }
    }
}
