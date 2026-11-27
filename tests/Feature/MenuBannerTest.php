<?php

use App\Models\Store;
use App\Models\User;
use App\Support\ScanMejaAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Toko yang hanya memakai Pesan Antar (modul Scan Meja mati).
 *
 * @return array{store: Store, admin: User}
 */
function menuBannerStore(): array
{
    $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Kedai Banner',
        'slug' => 'kedai-banner-'.uniqid(),
        'business_type' => 'fnb',
        'phone' => '081234567890',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
        'settings' => [
            'delivery_enabled' => true,
            'delivery_default_fee' => 8000,
            'delivery_self_order_enabled' => true,
            'plan_unlocked_modules' => ['delivery'],
        ],
    ]);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    return ['store' => $store, 'admin' => $admin];
}

function menuBannerFile(string $name = 'banner.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, ScanMejaAppearance::BANNER_WIDTH, ScanMejaAppearance::BANNER_HEIGHT);
}

test('banner bisa diunggah dari halaman Pengantaran tanpa modul Scan Meja dan tampil di halaman pesan antar', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();

    $this->actingAs($admin)
        ->post('/deliveries/appearance', [
            'cover' => UploadedFile::fake()->image('sampul.jpg', 1200, 500),
            'banners' => [menuBannerFile('satu.jpg'), menuBannerFile('dua.png')],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $store->fresh()->settings;
    expect($settings['scan_meja_banners'])->toHaveCount(2)
        ->and($settings['scan_meja_banners'][0])->toStartWith("scan-meja/{$store->id}/banner/")
        ->and($settings['scan_meja_cover'])->toStartWith("scan-meja/{$store->id}/");
    foreach ($settings['scan_meja_banners'] as $path) {
        Storage::disk('public')->assertExists($path);
    }

    // Halaman pelanggan menerima banner sesuai urutan unggah.
    $this->get('/antar/'.$store->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ScanMeja/Guest/Menu')
            ->where('store.banner_urls', array_map(fn ($p) => Storage::disk('public')->url($p), $settings['scan_meja_banners'])));

    // Form pengaturan di halaman Pengantaran menerima daftar banner dan keterangan ukurannya.
    $this->actingAs($admin)->get('/deliveries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deliveries/Index')
            ->has('appearance.banners', 2)
            ->where('appearance.banners.0.id', basename($settings['scan_meja_banners'][0]))
            ->where('appearance.banner_limits', ['max' => 5, 'width' => 1200, 'height' => 480, 'max_mb' => 2]));
});

test('menghapus satu banner membersihkan berkasnya dan menjaga urutan sisanya', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();

    $this->actingAs($admin)->post('/deliveries/appearance', [
        'banners' => [menuBannerFile('a.jpg'), menuBannerFile('b.jpg'), menuBannerFile('c.jpg')],
    ])->assertSessionHasNoErrors();
    [$a, $b, $c] = $store->fresh()->settings['scan_meja_banners'];

    $this->actingAs($admin)->post('/deliveries/appearance', [
        'remove_banners' => [basename($b)],
        'banners' => [menuBannerFile('d.jpg')],
    ])->assertSessionHasNoErrors();

    $after = $store->fresh()->settings['scan_meja_banners'];
    expect($after)->toHaveCount(3)
        ->and($after[0])->toBe($a)
        ->and($after[1])->toBe($c);
    Storage::disk('public')->assertMissing($b);
    Storage::disk('public')->assertExists($after[2]);

    // Menghapus semuanya mengosongkan pengaturan.
    $this->actingAs($admin)->post('/deliveries/appearance', [
        'remove_banners' => array_map('basename', $after),
    ])->assertSessionHasNoErrors();
    expect($store->fresh()->settings)->not->toHaveKey('scan_meja_banners');
});

test('banner dibatasi lima gambar dan penolakan tidak menghapus banner lama', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();

    $this->actingAs($admin)->post('/deliveries/appearance', [
        'banners' => [menuBannerFile('1.jpg'), menuBannerFile('2.jpg'), menuBannerFile('3.jpg'), menuBannerFile('4.jpg')],
    ])->assertSessionHasNoErrors();
    $before = $store->fresh()->settings['scan_meja_banners'];

    // 4 tersimpan − 1 dihapus + 3 baru = 6 → ditolak, dan banner yang "dihapus" tetap ada.
    $this->actingAs($admin)->post('/deliveries/appearance', [
        'remove_banners' => [basename($before[0])],
        'banners' => [menuBannerFile('5.jpg'), menuBannerFile('6.jpg'), menuBannerFile('7.jpg')],
    ])->assertSessionHasErrors('banners');

    expect($store->fresh()->settings['scan_meja_banners'])->toBe($before);
    Storage::disk('public')->assertExists($before[0]);
});

test('banner selain gambar atau lebih dari 2 MB ditolak', function () {
    Storage::fake('public');
    ['admin' => $admin] = menuBannerStore();

    $this->actingAs($admin)
        ->post('/deliveries/appearance', ['banners' => [UploadedFile::fake()->create('brosur.pdf', 10, 'application/pdf')]])
        ->assertSessionHasErrors('banners.0');

    $this->actingAs($admin)
        ->post('/deliveries/appearance', ['banners' => [UploadedFile::fake()->image('besar.jpg')->size(2500)]])
        ->assertSessionHasErrors('banners.0');
});

test('kasir tanpa hak mengatur toko tidak bisa mengubah tampilan', function () {
    Storage::fake('public');
    ['store' => $store] = menuBannerStore();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $cashier->stores()->attach($store->id, ['role' => 'cashier']);

    $this->actingAs($cashier)
        ->post('/deliveries/appearance', ['banners' => [menuBannerFile()]])
        ->assertForbidden();

    expect($store->fresh()->settings)->not->toHaveKey('scan_meja_banners');
});

test('banner dari Manajemen Meja juga tampil di menu digital meja', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();
    $store->update(['settings' => array_merge($store->settings, [
        'scan_meja_enabled' => true,
        'plan_unlocked_modules' => ['delivery', 'scan_meja'],
    ])]);

    $this->actingAs($admin)
        ->post('/tables/appearance', ['banners' => [menuBannerFile()]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $path = $store->fresh()->settings['scan_meja_banners'][0];
    $table = \App\Models\Table::query()->create(['store_id' => $store->id, 'name' => 'Meja 1', 'slug' => 'meja-banner', 'is_active' => true]);

    $this->get("/m/{$table->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('store.banner_urls', [Storage::disk('public')->url($path)]));
});

test('warna utama halaman pelanggan bisa dipilih, tampil di halaman pesan antar, dan bisa dikembalikan ke bawaan', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();

    $this->get('/antar/'.$store->slug)
        ->assertInertia(fn ($page) => $page->where('store.accent_color', null));

    $this->actingAs($admin)
        ->post('/deliveries/appearance', ['accent_color' => '#0D9488'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($store->fresh()->settings['scan_meja_color'])->toBe('#0d9488');

    $this->get('/antar/'.$store->slug)
        ->assertInertia(fn ($page) => $page->where('store.accent_color', '#0d9488'));
    $this->actingAs($admin)->get('/deliveries')
        ->assertInertia(fn ($page) => $page->where('appearance.accent_color', '#0d9488'));

    // Menyimpan hal lain tanpa mengirim warna tidak mengubah warna.
    $this->actingAs($admin)->post('/deliveries/appearance', ['banners' => [menuBannerFile()]])->assertSessionHasNoErrors();
    expect($store->fresh()->settings['scan_meja_color'])->toBe('#0d9488');

    // Warna dikosongkan = kembali ke bawaan.
    $this->actingAs($admin)->post('/deliveries/appearance', ['accent_color' => ''])->assertSessionHasNoErrors();
    expect($store->fresh()->settings)->not->toHaveKey('scan_meja_color');
});

test('warna yang bukan kode heksadesimal ditolak', function () {
    Storage::fake('public');
    ['store' => $store, 'admin' => $admin] = menuBannerStore();

    foreach (['merah', '#12345', 'rgb(1,2,3)', '#12345g', 'javascript:alert(1)'] as $buruk) {
        $this->actingAs($admin)
            ->post('/deliveries/appearance', ['accent_color' => $buruk])
            ->assertSessionHasErrors('accent_color');
    }

    expect($store->fresh()->settings)->not->toHaveKey('scan_meja_color');
});
