<?php

namespace Nativephp\BluetoothPrinter;

class BluetoothPrinter
{
    /**
     * Scan and return list of paired Bluetooth devices
     */
    public function getPairedDevices(): array
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('BluetoothPrinter.GetPairedDevices', json_encode([]));

            return json_decode($result, true)['data'] ?? [];
        }

        return [];
    }

    /**
     * Send ESC/POS data to a specific Bluetooth MAC address
     */
    public function print(string $macAddress, array $bytes): bool
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('BluetoothPrinter.Print', json_encode([
                'mac' => $macAddress,
                'bytes' => $bytes,
            ]));

            return (json_decode($result, true)['data']['status'] ?? '') === 'success';
        }

        return false;
    }
}
