<?php

namespace Nativephp\BluetoothPrinter\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getPairedDevices()
 * @method static bool print(string $macAddress, array $bytes)
 *
 * @see \Nativephp\BluetoothPrinter\BluetoothPrinter
 */
class BluetoothPrinter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativephp-bluetooth-printer';
    }
}
