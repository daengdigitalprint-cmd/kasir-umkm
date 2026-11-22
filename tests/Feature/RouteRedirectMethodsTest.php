<?php

/*
 * Route::redirect() mendaftarkan semua metode HTTP. Bila alamatnya sama dengan route PUT/POST,
 * di produksi (route:cache) permintaan simpan ikut dialihkan dan tidak pernah sampai ke controller.
 * Kejadian nyata: sakelar di Pengaturan toko tampak "Tersimpan" lalu kembali saat dimuat ulang.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('tidak ada route pengalihan yang menangkap metode selain GET/HEAD', function () {
    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getActionName(), 'RedirectController'))
        ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('PUT /settings/store diarahkan ke controller simpan pengaturan', function () {
    $route = Route::getRoutes()->match(Request::create('/settings/store', 'PUT'));

    expect($route->getName())->toBe('settings.store.update');
});

it('menyimpan sakelar modul lewat PUT /settings/store', function () {
    $store = \App\Models\Store::query()->create([
        'name' => 'Toko Sakelar', 'slug' => 'sakelar-'.uniqid(), 'business_type' => 'fnb',
        'is_active' => true, 'is_subscription_active' => true, 'settings' => ['ai_chat_enabled' => true],
    ]);
    $user = \App\Models\User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    $this->actingAs($user)->put('/settings/store', [
        'name' => 'Toko Sakelar', 'business_type' => 'fnb', 'ai_chat_enabled' => false, 'stamp_card_enabled' => true,
    ])->assertRedirect();

    $settings = $store->fresh()->settings;
    expect($settings['ai_chat_enabled'])->toBeFalse()->and($settings['stamp_card_enabled'])->toBeTrue();
});
