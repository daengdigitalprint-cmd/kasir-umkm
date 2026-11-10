<?php

namespace App\NativeComponents\Settings;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Nativephp\BluetoothPrinter\BluetoothPrinter;

class BluetoothPrinterSettings extends NativeComponent
{
    public array $pairedDevices = [];

    public ?string $selectedDeviceAddress = null;

    public string $connectionStatus = 'Disconnected';

    public string $testPrintStatus = '';

    public function navTitle(): string
    {
        return 'Printer Bluetooth';
    }

    public function mount(): void
    {
        $this->loadPairedDevices();
    }

    public function loadPairedDevices(): void
    {
        try {
            $devices = BluetoothPrinter::getPairedDevices();
            // Data kembalian dari plugin mungkin berupa string JSON atau array
            if (is_string($devices)) {
                $devices = json_decode($devices, true) ?? [];
            }
            $this->pairedDevices = is_array($devices) ? $devices : [];
        } catch (\Exception $e) {
            $this->pairedDevices = [];
            $this->testPrintStatus = 'Gagal memuat perangkat: '.$e->getMessage();
        }
    }

    public function selectDevice(string $address): void
    {
        $this->selectedDeviceAddress = $address;
        $this->connectionStatus = 'Selected (Ready to Print)';
        $this->testPrintStatus = '';
    }

    public function testPrint(): void
    {
        if (! $this->selectedDeviceAddress) {
            $this->testPrintStatus = 'Pilih perangkat terlebih dahulu.';

            return;
        }

        try {
            $this->testPrintStatus = 'Mencetak...';

            // Dummy byte array untuk test print (inisialisasi + teks + LF)
            // ESC @, "Test Print Sukses!\n", LF, LF
            $bytes = [27, 64, 84, 101, 115, 116, 32, 80, 114, 105, 110, 116, 32, 83, 117, 107, 115, 101, 115, 33, 10, 10];

            $result = BluetoothPrinter::print($this->selectedDeviceAddress, $bytes);

            if ($result) {
                $this->testPrintStatus = 'Test print sukses!';
            } else {
                $this->testPrintStatus = 'Gagal mencetak. Periksa koneksi printer.';
            }
        } catch (\Exception $e) {
            $this->testPrintStatus = 'Error mencetak: '.$e->getMessage();
        }
    }

    public function render(): Element|View
    {
        return view('native.settings.bluetooth-printer');
    }
}
