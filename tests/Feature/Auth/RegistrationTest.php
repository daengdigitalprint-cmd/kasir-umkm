<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'whatsapp' => '0812-3456-7890',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('stores.create', absolute: false));
});

test('pengguna baru melihat onboarding ringkas, bukan form outlet lengkap', function () {
    $user = User::factory()->create(['store_id' => null]);

    $this->actingAs($user)
        ->get(route('stores.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Stores/Onboarding'));
});

test('toko pertama bisa dibuat hanya dengan nama dan jenis usaha', function () {
    $user = User::factory()->create([
        'store_id' => null,
        'phone' => '6281234567890',
        'email' => 'pemilik@example.com',
    ]);

    $this->actingAs($user)
        ->post(route('stores.store'), [
            'name' => 'Warung Makmur',
            'business_type' => 'retail',
            'timezone' => 'Asia/Makassar',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $store = $user->fresh()->store;

    expect($store)->not->toBeNull()
        ->and($store->name)->toBe('Warung Makmur')
        ->and($store->business_type)->toBe('retail')
        ->and($store->currency)->toBe('IDR')
        ->and($store->timezone)->toBe('Asia/Makassar')
        ->and($store->phone)->toBe('081234567890')
        ->and($store->email)->toBe('pemilik@example.com')
        ->and($store->organization_id)->not->toBeNull();
});

test('nomor whatsapp dinormalkan ke format 62 saat disimpan', function (string $input) {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'wa@example.com',
        'whatsapp' => $input,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::where('email', 'wa@example.com')->value('phone'))->toBe('6281234567890');
})->with(['0812 3456 7890', '+62 812-3456-7890', '6281234567890', '81234567890']);

test('nomor whatsapp wajib diisi', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'nowa@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('whatsapp');

    $this->assertGuest();
});

test('nomor whatsapp yang tidak valid ditolak', function (string $input) {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'badwa@example.com',
        'whatsapp' => $input,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('whatsapp');

    $this->assertGuest();
})->with(['12345', '021-555-1234', 'bukan nomor']);
