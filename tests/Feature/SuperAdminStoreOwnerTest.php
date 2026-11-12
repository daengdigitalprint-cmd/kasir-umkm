<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dashboard superadmin menampilkan pemilik toko beserta nomor WhatsApp', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $owner = User::factory()->create(['name' => 'Pemilik Toko', 'phone' => '6281234567890']);
    $store = Store::create(['name' => 'Toko Uji', 'slug' => 'toko-uji-'.Str::random(5), 'is_active' => true]);
    $owner->stores()->attach($store->id, ['role' => 'admin']);

    $cashier = User::factory()->create(['phone' => '6289999999999']);
    $cashier->stores()->attach($store->id, ['role' => 'cashier']);

    $response = $this->actingAs($superAdmin)->get(route('superadmin.dashboard'));

    $response->assertOk();
    $row = collect($response->viewData('page')['props']['stores']['data'])->firstWhere('id', $store->id);

    expect($row['owner'])->toMatchArray([
        'name' => 'Pemilik Toko',
        'phone' => '6281234567890',
    ]);
});
