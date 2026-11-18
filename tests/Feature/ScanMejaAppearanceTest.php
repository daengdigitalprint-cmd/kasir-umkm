<?php

use App\Models\Store;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @return array{store: Store, user: User}
 */
function scanMejaAppearanceStore(): array
{
    $store = Store::query()->create([
        'name' => 'Kedai Tampilan',
        'slug' => 'kedai-tampilan-'.uniqid(),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => ['scan_meja_enabled' => true],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return ['store' => $store, 'user' => $user];
}

test('pemilik bisa mengunggah sampul dan logo menu digital', function () {
    Storage::fake('public');
    ['store' => $store, 'user' => $user] = scanMejaAppearanceStore();

    $this->actingAs($user)
        ->post('/tables/appearance', [
            'cover' => UploadedFile::fake()->image('sampul.jpg', 1200, 500),
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $store->fresh()->settings;
    expect($settings['scan_meja_cover'])->toStartWith("scan-meja/{$store->id}/")
        ->and($settings['scan_meja_logo'])->toStartWith("scan-meja/{$store->id}/");
    Storage::disk('public')->assertExists($settings['scan_meja_cover']);
    Storage::disk('public')->assertExists($settings['scan_meja_logo']);

    // Halaman menu tamu ikut memakai gambar baru.
    $table = Table::query()->create(['store_id' => $store->id, 'name' => 'Meja 1', 'slug' => 'meja-tampilan', 'is_active' => true]);
    $this->get("/m/{$table->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ScanMeja/Guest/Menu')
            ->where('store.cover_url', Storage::disk('public')->url($settings['scan_meja_cover']))
            ->where('store.logo_url', Storage::disk('public')->url($settings['scan_meja_logo'])));
});

test('mengganti dan menghapus gambar ikut membersihkan file lama', function () {
    Storage::fake('public');
    ['store' => $store, 'user' => $user] = scanMejaAppearanceStore();

    $this->actingAs($user)->post('/tables/appearance', ['cover' => UploadedFile::fake()->image('a.jpg')]);
    $first = $store->fresh()->settings['scan_meja_cover'];

    $this->actingAs($user)->post('/tables/appearance', ['cover' => UploadedFile::fake()->image('b.jpg')]);
    $second = $store->fresh()->settings['scan_meja_cover'];

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);

    $this->actingAs($user)->post('/tables/appearance', ['remove_cover' => true])->assertRedirect();

    expect($store->fresh()->settings)->not->toHaveKey('scan_meja_cover');
    Storage::disk('public')->assertMissing($second);
});

test('unggahan selain gambar ditolak', function () {
    Storage::fake('public');
    ['user' => $user] = scanMejaAppearanceStore();

    $this->actingAs($user)
        ->post('/tables/appearance', ['cover' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('cover');
});
