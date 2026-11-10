<?php

namespace Nativephp\BluetoothPrinter;

use Illuminate\Support\ServiceProvider;

class BluetoothPrinterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('nativephp-bluetooth-printer', function () {
            return new BluetoothPrinter;
        });
    }

    public function boot(): void
    {
        //
    }
}
