<?php

use App\NativeComponents\Absensi\Index as AbsensiIndex;
use App\NativeComponents\Auth\Login;
use App\NativeComponents\Gaji\Index as GajiIndex;
use App\NativeComponents\Kas\Index as KasIndex;
use App\NativeComponents\Kasir\Index as KasirIndex;
use App\NativeComponents\Lainnya\Index as LainnyaIndex;
use App\NativeComponents\Laporan\Index as LaporanIndex;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Pelanggan\Index as PelangganIndex;
use App\NativeComponents\Produk\Index as ProdukIndex;
use App\NativeComponents\Settings\BluetoothPrinterSettings;
use App\NativeComponents\Toko\Pengaturan as TokoPengaturan;
use App\NativeComponents\Toko\Users as TokoUsers;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile Routes (NativePHP Android EDGE)
|--------------------------------------------------------------------------
|
| Rute ini didaftarkan khusus untuk shell NativePHP Mobile EDGE.
| Digunakan untuk aplikasi Android native tanpa mengganggu rute web Inertia.
|
*/

// Layar autentikasi / start screen jika belum login di aplikasi mobile
Route::native('/mobile/login', Login::class)->name('mobile.login');
Route::native('/mobile/settings/printer', BluetoothPrinterSettings::class)->name('mobile.settings.printer');

// Grup tab navigasi utama yang dibungkus oleh TabsLayout
Route::nativeGroup(TabsLayout::class, function (): void {
    Route::native('/app', KasirIndex::class)->name('mobile.kasir');
    Route::native('/app/produk', ProdukIndex::class)->name('mobile.produk');
    Route::native('/app/laporan', LaporanIndex::class)->name('mobile.laporan');
    Route::native('/app/lainnya', LainnyaIndex::class)->name('mobile.lainnya');
    Route::native('/app/pelanggan', PelangganIndex::class)->name('mobile.pelanggan');
    Route::native('/app/kas', KasIndex::class)->name('mobile.kas');
    Route::native('/app/absensi', AbsensiIndex::class)->name('mobile.absensi');
    Route::native('/app/gaji', GajiIndex::class)->name('mobile.gaji');
    Route::native('/app/toko/pengaturan', TokoPengaturan::class)->name('mobile.toko.pengaturan');
    Route::native('/app/toko/users', TokoUsers::class)->name('mobile.toko.users');
});
