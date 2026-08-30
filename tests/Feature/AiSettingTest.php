<?php

use App\Models\AiSetting;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSettingOrg(): array
{
    $store = Store::query()->create([
        'name' => 'Setting Store',
        'slug' => 'setting-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $superAdmin = User::factory()->create([
        'name' => 'Setting SuperAdmin',
        'email' => 'setting-sa-'.uniqid().'@test.com',
        'is_super_admin' => true,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $cashier = User::factory()->create([
        'name' => 'Setting Cashier',
        'email' => 'setting-cs-'.uniqid().'@test.com',
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $superAdmin, $cashier];
}

test('model aisetting dapat menyimpan dan mengambil konfigurasi', function () {
    expect(AiSetting::get('provider'))->toBeNull();

    AiSetting::set('provider', 'anthropic');
    expect(AiSetting::get('provider'))->toBe('anthropic');

    AiSetting::set('api_key', 'sk-ant-12345');
    expect(AiSetting::apiKey())->toBe('sk-ant-12345');

    AiSetting::remove('api_key');
    expect(AiSetting::get('api_key'))->toBeNull();
});

test('model aisetting membaca fallback dari config env', function () {
    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.key' => 'sk-env-key-123']);
    
    AiSetting::query()->delete();

    expect(AiSetting::activeProvider())->toBe('openai');
    expect(AiSetting::apiKey())->toBe('sk-env-key-123');
    expect(AiSetting::maskedKey())->toBe('sk-env...-123');

    // Jika DB diatur, maka menimpa env
    AiSetting::set('provider', 'gemini');
    AiSetting::set('api_key', 'AIza-db-key');
    
    expect(AiSetting::activeProvider())->toBe('gemini');
    expect(AiSetting::apiKey())->toBe('AIza-db-key');
});

test('applytoruntime benar benar menimpa config runtime laravel', function () {
    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.key' => 'sk-env-key']);
    
    AiSetting::set('provider', 'anthropic');
    AiSetting::set('api_key', 'sk-ant-12345');
    
    AiSetting::applyToRuntime();
    
    expect(config('ai.default'))->toBe('anthropic');
    expect(config('ai.providers.anthropic.key'))->toBe('sk-ant-12345');
});

test('super admin dapat membuka halaman pengaturan ai', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $this->get('/superadmin/ai-settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('AiSetting/Index')
            ->has('providers')
            ->has('activeProvider')
        );
});

test('user non superadmin ditolak akses halaman pengaturan', function () {
    [, , $cashier] = makeSettingOrg();

    $this->actingAs($cashier);

    $this->get('/superadmin/ai-settings')->assertForbidden();
    $this->put('/superadmin/ai-settings')->assertForbidden();
});

test('super admin dapat mengubah provider dan api key via api', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $response = $this->putJson('/superadmin/ai-settings', [
        'provider' => 'gemini',
        'api_key' => 'AIzaSyA-1234567890',
    ]);

    $response->assertOk()->assertJson([
        'success' => true,
        'activeProvider' => 'gemini',
        'hasApiKey' => true,
        'source' => 'database',
    ]);

    expect(AiSetting::get('provider'))->toBe('gemini');
    expect(AiSetting::get('api_key'))->toBe('AIzaSyA-1234567890');
});

test('validasi menolak provider yang tidak didukung', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $this->putJson('/superadmin/ai-settings', [
        'provider' => 'unknown-provider',
        'api_key' => '123',
    ])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
});

test('mengubah provider tanpa mengisi key akan mempertahankan key lama', function () {
    [, $superAdmin] = makeSettingOrg();
    
    AiSetting::set('provider', 'openai');
    AiSetting::set('api_key', 'sk-old-key');

    $this->actingAs($superAdmin);

    $this->putJson('/superadmin/ai-settings', [
        'provider' => 'anthropic',
        'api_key' => '', // kosong
    ])->assertOk();

    expect(AiSetting::get('provider'))->toBe('anthropic');
    expect(AiSetting::get('api_key'))->toBe('sk-old-key');
});

test('super admin dapat menghapus api key dari database', function () {
    [, $superAdmin] = makeSettingOrg();
    
    AiSetting::set('api_key', 'sk-hapus-ini');

    $this->actingAs($superAdmin);

    $response = $this->deleteJson('/superadmin/ai-settings');

    $response->assertOk();
    expect(AiSetting::get('api_key'))->toBeNull();
});
