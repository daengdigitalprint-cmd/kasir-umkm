<?php

use App\Services\AI\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function detectAiPurchaseIntent(string $message): ?array
{
    $service = new AiService;
    $method = new ReflectionMethod($service, 'detectDirectToolCall');
    $method->setAccessible(true);

    return $method->invoke($service, $message, null);
}

it('mengenali pesan beli stok dari supplier sebagai purchase order', function () {
    $result = detectAiPurchaseIntent('Beliin stok Air Mineral 900ml 20 pcs dari supplier Maju Jaya, bayar cash');

    expect($result['tool'])->toBe('create_purchase_order')
        ->and($result['arguments']['items'][0]['product_name'])->toBe('Air Mineral 900ml')
        ->and($result['arguments']['items'][0]['quantity'])->toBe(20)
        ->and($result['arguments']['items'][0]['unit'])->toBe('pcs')
        ->and($result['arguments']['supplier_name'])->toBe('Maju Jaya')
        ->and($result['arguments']['payment_method'])->toBe('cash');
});

it('mengenali pembayaran kredit/tempo pada pesan pembelian', function () {
    $result = detectAiPurchaseIntent('Pesan Teh Botol 50 dus dari supplier ABC, bayar tempo');

    expect($result['tool'])->toBe('create_purchase_order')
        ->and($result['arguments']['items'][0]['product_name'])->toBe('Teh Botol')
        ->and($result['arguments']['items'][0]['quantity'])->toBe(50)
        ->and($result['arguments']['payment_method'])->toBe('credit');
});

it('mengenali beli tanpa supplier jika jumlah dan satuan jelas', function () {
    $result = detectAiPurchaseIntent('beli kopi sachet 100 pcs');

    expect($result['tool'])->toBe('create_purchase_order')
        ->and($result['arguments']['items'][0]['product_name'])->toBe('kopi sachet')
        ->and($result['arguments']['items'][0]['quantity'])->toBe(100)
        ->and($result['arguments'])->not->toHaveKey('supplier_name');
});

it('tidak mengenali pesan beli tanpa jumlah sebagai purchase order', function () {
    expect(detectAiPurchaseIntent('Beliin stok dari supplier Maju Jaya'))->toBeNull();
});

it('tetap mengenali tambah produk meski ada kata beli pada harga beli', function () {
    $result = detectAiPurchaseIntent('Tambah produk Kopi Susu, harga jual 10000, harga beli 7000, stok 50');

    expect($result['tool'])->toBe('create_product')
        ->and($result['arguments']['price'])->toBe(10000.0)
        ->and($result['arguments']['cost'])->toBe(7000.0);
});

it('tetap mengenali tambah produk baru tanpa konteks pembelian', function () {
    $result = detectAiPurchaseIntent('Tambah produk Teh Botol, harga 6000, modal 4000, stok 100 pcs');

    expect($result['tool'])->toBe('create_product')
        ->and($result['arguments']['name'])->toBe('Teh Botol');
});

it('purchase order memerlukan konfirmasi sebelum dieksekusi', function () {
    $service = new AiService;
    $method = new ReflectionMethod($service, 'requiresConfirmation');
    $method->setAccessible(true);

    expect($method->invoke($service, 'create_purchase_order'))->toBeTrue();
});
