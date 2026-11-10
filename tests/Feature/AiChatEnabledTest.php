<?php

use App\Models\Store;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function storeWithAiChatSetting(bool $enabled): Store
{
    return Store::query()->create([
        'name' => 'Toko AI',
        'slug' => 'toko-ai-'.uniqid(),
        'business_type' => 'general',
        'settings' => ['ai_chat_enabled' => $enabled],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

test('admin toko dapat menyimpan status Chat AI', function () {
    $store = storeWithAiChatSetting(true);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->actingAs($user)
        ->put(route('settings.store.update'), [
            'name' => $store->name,
            'business_type' => $store->business_type,
            'ai_chat_enabled' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($store->refresh()->settings['ai_chat_enabled'])->toBeFalse();
});

test('seluruh rute Chat AI diblokir untuk toko yang menonaktifkannya', function () {
    $store = storeWithAiChatSetting(false);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->actingAs($user)
        ->get(route('ai.chat.page'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->postJson(route('ai.chat.stream'), ['message' => 'Halo'])
        ->assertForbidden();
});

test('Chat AI mengikuti toko aktif pengguna multi-outlet', function () {
    $enabledStore = storeWithAiChatSetting(true);
    $disabledStore = storeWithAiChatSetting(false);
    $user = User::factory()->create(['store_id' => $disabledStore->id, 'role' => 'admin']);
    $user->stores()->attach([
        $enabledStore->id => ['role' => 'admin'],
        $disabledStore->id => ['role' => 'admin'],
    ]);

    $this->actingAs($user)
        ->get(route('ai.chat.page'))
        ->assertRedirect(route('dashboard'));

    $user->update(['store_id' => $enabledStore->id]);

    $this->actingAs($user->fresh())
        ->get(route('ai.chat.page'))
        ->assertSuccessful();
});

test('Chat AI tetap aktif secara default untuk toko tanpa pengaturan eksplisit', function () {
    $store = storeWithAiChatSetting(true);
    $store->update(['settings' => []]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->actingAs($user)
        ->get(route('ai.chat.page'))
        ->assertSuccessful();
});
