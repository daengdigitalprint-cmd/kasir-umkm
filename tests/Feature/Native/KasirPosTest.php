<?php

use App\Enums\SalesChannel;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\SyncOutbox;
use App\Models\Transaction;
use App\Models\User;
use App\NativeComponents\Kasir\Index as KasirIndex;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Berkah Mobile',
        'code' => 'TBM01',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Siti Kasir',
        'email' => 'siti@toko.com',
        'role' => 'cashier',
        'store_id' => $this->store->id,
    ]);

    $this->category = ProductCategory::query()->create([
        'store_id' => $this->store->id,
        'name' => 'Minuman Dingin',
        'slug' => 'minuman-dingin',
        'sort_order' => 1,
    ]);

    $this->product = Product::query()->create([
        'store_id' => $this->store->id,
        'product_category_id' => $this->category->id,
        'name' => 'Kopi Susu Gula Aren',
        'sku' => 'KPS-001',
        'price' => 18000,
        'stock_quantity' => 25,
        'min_stock_level' => 5,
        'is_active' => true,
    ]);
});

test('layar kasir memuat info kasir dan mendeteksi ketiadaan shift aktif', function () {
    $this->actingAs($this->user);

    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSee('Siti Kasir')
        ->assertSee('Buka Shift')
        ->assertSet('isShiftOpen', false);
});

test('kasir dapat membuka shift kasir baru dengan modal awal', function () {
    $this->actingAs($this->user);

    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openShiftModal')
        ->assertSet('showShiftModal', true)
        ->set('openingCashInput', '150000')
        ->call('startShift');

    $component->assertSet('isShiftOpen', true)
        ->assertSet('showShiftModal', false);

    expect(CashShift::where('store_id', $this->store->id)->count())->toBe(1);
    $shift = CashShift::first();
    expect((float) $shift->opening_cash)->toBe(150000.0)
        ->and((float) $shift->expected_cash)->toBe(150000.0)
        ->and($shift->closed_at)->toBeNull();
});

test('menambahkan produk ke keranjang belanja saat shift terbuka dan menghitung subtotal', function () {
    $this->actingAs($this->user);

    // Siapkan shift kasir
    $shift = CashShift::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'opened_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 100000,
    ]);

    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('isShiftOpen', true)
        ->call('addToCart', $this->product->id);

    $cart = $component->get('cart');
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['product_id'])->toBe($this->product->id)
        ->and($cart[0]['qty'])->toBe(1)
        ->and($cart[0]['subtotal'])->toBe(18000.0);

    // Tambah qty melalui increment
    $component->call('incrementCart', $this->product->id);
    $cart = $component->get('cart');
    expect($cart[0]['qty'])->toBe(2)
        ->and($cart[0]['subtotal'])->toBe(36000.0);

    // Kurangi qty melalui decrement
    $component->call('decrementCart', $this->product->id);
    $cart = $component->get('cart');
    expect($cart[0]['qty'])->toBe(1);
});

test('proses checkout tunai berhasil memotong stok lokal dan mencatat outbox sync', function () {
    $this->actingAs($this->user);

    CashShift::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'opened_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 100000,
    ]);

    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('addToCart', $this->product->id)
        ->call('openCheckout')
        ->assertSet('showCheckoutModal', true)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '20000')
        ->call('calculateChange')
        ->assertSet('changeAmount', 2000.0)
        ->call('processCheckout');

    $component->assertSet('showSuccessModal', true)
        ->assertSet('showCheckoutModal', false)
        ->assertSet('cart', []);

    // Verifikasi Transaksi di database lokal SQLite
    $trx = Transaction::first();
    expect($trx)->not->toBeNull()
        ->and((float) $trx->total_amount)->toBe(18000.0)
        ->and((float) $trx->amount_paid)->toBe(20000.0)
        ->and((float) $trx->change_amount)->toBe(2000.0)
        ->and($trx->payment_method)->toBe('cash')
        ->and($trx->sales_channel)->toBe(SalesChannel::WalkIn);

    // Verifikasi pemotongan stok lokal
    $this->product->refresh();
    expect((float) $this->product->stock_quantity)->toBe(24.0);

    // Verifikasi pencatatan antrian outbox sinkronisasi
    expect(SyncOutbox::where('entity_type', 'transaction')->count())->toBe(1);
    $outbox = SyncOutbox::first();
    expect((float) $outbox->payload['total_amount'])->toBe(18000.0)
        ->and($outbox->synced_at)->toBeNull();
});

test('kasir dapat menutup shift kasir dengan uang fisik', function () {
    $this->actingAs($this->user);

    $shift = CashShift::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'opened_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 100000,
    ]);

    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openShiftModal')
        ->set('closingCashInput', '100000')
        ->call('endShift');

    $component->assertSet('isShiftOpen', false)
        ->assertSet('activeShiftId', null);

    $shift->refresh();
    expect($shift->closed_at)->not->toBeNull()
        ->and((float) $shift->closing_cash_counted)->toBe(100000.0)
        ->and((float) $shift->difference_amount)->toBe(0.0);
});
