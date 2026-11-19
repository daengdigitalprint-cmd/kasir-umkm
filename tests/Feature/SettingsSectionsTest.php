<?php

use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function settingsSectionStore(array $settings = []): Store
{
    return Store::query()->create([
        'name' => 'Toko Pengaturan',
        'slug' => 'toko-pengaturan-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);
}

function settingsSectionUser(Store $store, string $role = 'admin'): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

test('setiap bagian pengaturan toko punya alamat sendiri', function (string $section) {
    $store = settingsSectionStore();

    $this->actingAs(settingsSectionUser($store))
        ->get("/settings/store/{$section}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Profile')->where('section', $section));
})->with(['profil', 'kasir', 'fitur', 'struk', 'stok', 'backup']);

test('alamat lama diarahkan ke bagian yang baru', function () {
    $store = settingsSectionStore();
    $this->actingAs(settingsSectionUser($store));

    $this->get('/settings/store')->assertRedirect('/settings/store/profil');
    $this->get('/settings/features')->assertRedirect('/settings/store/fitur');
    $this->get('/settings/backup')->assertRedirect('/settings/store/backup');
    $this->get('/settings/store/ngawur')->assertNotFound();
});

test('kasir hanya bisa membuka pengaturan akun', function () {
    $store = settingsSectionStore();
    $this->actingAs(settingsSectionUser($store, 'cashier'));

    $this->get('/settings/profile')->assertOk()->assertInertia(fn ($page) => $page->where('section', 'akun'));
    $this->get('/settings/store/kasir')->assertRedirect();
});

test('menyimpan satu bagian tidak menimpa pengaturan bagian lain', function () {
    $store = settingsSectionStore([
        'receipt_header_name' => 'Nama Struk Lama',
        'batch_tracking_enabled' => true,
        'online_sales_enabled' => true,
    ]);

    // Bagian "Kasir & pembayaran" hanya mengirim kolomnya sendiri.
    $this->actingAs(settingsSectionUser($store))
        ->put('/settings/store', [
            'name' => 'Toko Pengaturan',
            'business_type' => 'retail',
            'product_discount_enabled' => true,
        ])
        ->assertSessionHasNoErrors();

    $settings = $store->fresh()->settings;
    expect($settings['receipt_header_name'])->toBe('Nama Struk Lama')
        ->and($settings['batch_tracking_enabled'])->toBeTrue()
        ->and($settings['online_sales_enabled'])->toBeTrue()
        ->and($settings['product_discount_enabled'])->toBeTrue();
});
