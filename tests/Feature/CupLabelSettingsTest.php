<?php

use App\Models\Store;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('store cup_label_enabled setting can be updated and passed to pos', function () {
    $store = Store::query()->create([
        'name' => 'Warkop Barista',
        'slug' => 'warkop-barista-'.uniqid(),
        'business_type' => 'fnb',
        'settings' => ['cup_label_enabled' => false],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => 'fnb',
            'cup_label_enabled' => true,
        ]);

    $response->assertSessionHasNoErrors();
    $store->refresh();
    expect($store->settings['cup_label_enabled'] ?? false)->toBeTrue();

    $posResponse = $this
        ->actingAs($user)
        ->get(route('pos.index'));

    $posResponse->assertOk();
    $posResponse->assertInertia(fn ($page) => $page
        ->component('POS/Index')
        ->where('store.cup_label_enabled', true)
    );
});
