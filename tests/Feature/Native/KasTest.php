<?php

use App\Models\CashTransaction;
use App\Models\Store;
use App\Models\User;
use App\NativeComponents\Kas\Index as KasIndex;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Kas Test',
        'code' => 'TKT',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Siti Kasir',
        'email' => 'siti@toko.com',
        'role' => 'cashier',
        'store_id' => $this->store->id,
    ]);
});

test('halaman kas menampilkan statistik kas hari ini', function () {
    $this->actingAs($this->user);

    CashTransaction::query()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'type' => 'in',
        'amount' => 150000,
        'description' => 'Modal awal',
        'category' => 'revenue',
    ]);

    CashTransaction::query()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'type' => 'out',
        'amount' => 50000,
        'description' => 'Bayar listrik',
        'category' => 'expense',
    ]);

    $component = Native::test(KasIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('todayIn', 'Rp 150.000')
        ->assertSet('todayOut', 'Rp 50.000')
        ->assertSet('balance', '+ Rp 100.000');
});

test('kasir dapat menambah kas masuk', function () {
    $this->actingAs($this->user);

    $component = Native::test(KasIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal', 'in')
        ->assertSet('showAddModal', true)
        ->assertSet('type', 'in')
        ->set('amountInput', '200000')
        ->set('description', 'Penjualan pagi')
        ->call('saveTransaction')
        ->assertSet('showAddModal', false);

    expect(CashTransaction::where('type', 'in')->where('amount', 200000)->exists())->toBeTrue();
});

test('kasir dapat menambah kas keluar', function () {
    $this->actingAs($this->user);

    $component = Native::test(KasIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal', 'out')
        ->set('amountInput', '75000')
        ->set('description', 'Beli plastik')
        ->call('saveTransaction')
        ->assertSet('showAddModal', false);

    expect(CashTransaction::where('type', 'out')->where('amount', 75000)->exists())->toBeTrue();
});

test('validasi mencegah nominal tidak valid', function () {
    $this->actingAs($this->user);

    $component = Native::test(KasIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal', 'in')
        ->set('amountInput', 'abc')
        ->call('saveTransaction')
        ->assertSet('showAddModal', true)
        ->assertSet('formError', 'Nominal tidak valid.');

    expect(CashTransaction::count())->toBe(0);
});
