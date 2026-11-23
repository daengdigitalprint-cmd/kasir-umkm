<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Observers\ProductObserver;
use App\Observers\TransactionObserver;
use App\Services\AI\AiPeriod;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Email bawaan Laravel (reset & verifikasi) memakai tampilan email Kasir UMKM.
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $broker = (string) config('auth.defaults.passwords');

            return (new MailMessage)
                ->subject('Atur ulang kata sandi Kasir UMKM')
                ->view('emails.reset-password', [
                    'name' => $notifiable->name ?? null,
                    'email' => $notifiable->getEmailForPasswordReset(),
                    'url' => route('password.reset', [
                        'token' => $token,
                        'email' => $notifiable->getEmailForPasswordReset(),
                    ]),
                    'time' => self::emailTime($notifiable),
                    'expireMinutes' => (int) config("auth.passwords.{$broker}.expire", 60),
                ]);
        });

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject('Verifikasi alamat email Kasir UMKM')
            ->view('emails.verify-email', [
                'name' => $notifiable->name ?? null,
                'email' => $notifiable->getEmailForVerification(),
                'url' => $url,
                'time' => self::emailTime($notifiable),
            ]));

        // Force HTTPS in production
        if (app()->environment('production')) {
            URL::forceScheme('https');
            // URL yang dibuat (canonical, og:url, sitemap, link email) selalu memakai APP_URL,
            // bukan host yang sedang diakses (mis. www.).
            URL::forceRootUrl((string) config('app.url'));
        }
    }

    /** Waktu sekarang menurut jam toko pengguna (default WIB) untuk isi email. */
    private static function emailTime(object $notifiable): string
    {
        $store = $notifiable instanceof User ? $notifiable->store : null;
        $store ??= new Store(['timezone' => 'Asia/Jakarta']);

        return AiPeriod::now($store)->locale('id')->translatedFormat('d F Y, H:i').' '.AiPeriod::timezoneLabel($store);
    }
}
