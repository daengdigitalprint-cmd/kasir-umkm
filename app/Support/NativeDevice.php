<?php

namespace App\Support;

class NativeDevice
{
    /**
     * Menentukan apakah permintaan saat ini berjalan di dalam container aplikasi NativePHP Mobile.
     */
    public static function isMobileApp(): bool
    {
        return (bool) (
            config('nativephp-internal.running')
            || env('NATIVEPHP_RUNNING')
            || env('JUMP_BRIDGE_PORT')
        );
    }
}
