<?php

use App\Models\AiSetting;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
    config(['ai.default_provider' => 'openai']);
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
    config(['ai.default_provider' => 'openai']);
    config(['ai.providers.openai.key' => 'sk-env-key']);

    AiSetting::set('provider', 'anthropic');
    AiSetting::set('api_key', 'sk-ant-12345');

    AiSetting::applyToRuntime();

    expect(config('ai.default_provider'))->toBe('anthropic');
    expect(config('ai.providers.anthropic.key'))->toBe('sk-ant-12345');
    expect(config('ai.providers.anthropic.api_key'))->toBe('sk-ant-12345');
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
    $this->post('/superadmin/ai-settings/test-connection')->assertForbidden();
});

test('super admin dapat mengubah provider dan api key via api', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $response = $this->putJson('/superadmin/ai-settings', [
        'provider' => 'custom',
        'api_key' => 'AIzaSyA-1234567890',
        'url' => 'https://api.openai.com/v1',
    ]);

    $response->assertOk();
    expect(AiSetting::get('provider'))->toBe('custom');
    expect(AiSetting::get('api_key'))->toBe('AIzaSyA-1234567890');
    expect(AiSetting::get('url'))->toBe('https://api.openai.com/v1');
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
        'provider' => 'custom',
        'url' => 'https://api.openai.com/v1',
        'api_key' => '', // kosong
    ])->assertOk();

    expect(AiSetting::get('provider'))->toBe('custom');
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

test('test connection gagal tanpa api key', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    AiSetting::query()->delete();

    $response = $this->postJson('/superadmin/ai-settings/test-connection', [
        'provider' => 'openai',
    ]);

    $response->assertStatus(422)->assertJsonPath('success', false);
    expect($response->json('message'))->toContain('API Key belum dikonfigurasi');
});

test('test connection gagal saat api tidak merespons', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    Http::fake(fn () => throw new \Exception('Connection refused'));

    $response = $this->postJson('/superadmin/ai-settings/test-connection', [
        'provider' => 'openai',
        'api_key' => 'sk-test-key',
    ]);

    $response->assertStatus(422)->assertJsonPath('success', false);
    expect($response->json('message'))->toContain('Gagal terhubung ke AI');
});

test('test connection berhasil saat api merespons', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
        ], 200),
    ]);

    $response = $this->postJson('/superadmin/ai-settings/test-connection', [
        'provider' => 'openai',
        'api_key' => 'sk-test-key',
    ]);

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('message'))->toContain('Koneksi berhasil');
});

test('test connection menolak provider yang tidak valid', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $this->postJson('/superadmin/ai-settings/test-connection', [
        'provider' => 'invalid-provider',
        'api_key' => 'sk-test-key',
    ])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
});

test('test connection custom membutuhkan url', function () {
    [, $superAdmin] = makeSettingOrg();

    $this->actingAs($superAdmin);

    $response = $this->postJson('/superadmin/ai-settings/test-connection', [
        'provider' => 'custom',
        'api_key' => 'sk-test-key',
    ]);

    $response->assertStatus(422)->assertJsonPath('success', false);
    expect($response->json('message'))->toContain('URL endpoint wajib diisi');
});

test('applytoruntime menangani provider yang belum ada di config ai', function () {
    AiSetting::set('provider', 'groq');
    AiSetting::set('api_key', 'gsk_123');

    AiSetting::applyToRuntime();

    expect(config('ai.default_provider'))->toBe('groq');
    expect(config('ai.providers.groq.api_key'))->toBe('gsk_123');
    expect(config('ai.providers.groq.base_url'))->toBe('https://api.groq.com/openai/v1');
});

test('chat memakai api key dari pengaturan super admin saat env kosong', function () {
    AiSetting::set('provider', 'openai');
    AiSetting::set('api_key', 'sk-super-admin-key');

    // Simulasikan .env tidak punya API key
    config(['ai.providers.openai.key' => null]);
    config(['ai.providers.openai.api_key' => null]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Saya bisa bantu banyak hal!']]],
        ], 200),
    ]);

    $user = User::factory()->create();

    $result = (new AiService($user))->chat('kamu bisa bantu apa saja?');

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-super-admin-key'));
});

test('chat memakai url custom dari pengaturan super admin', function () {
    AiSetting::set('provider', 'custom');
    AiSetting::set('api_key', 'sk-custom-key');
    AiSetting::set('url', 'https://my-proxy.example.com/v1');

    Http::fake([
        'my-proxy.example.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
        ], 200),
    ]);

    $user = User::factory()->create();

    $result = (new AiService($user))->chat('kamu bisa bantu apa saja?');

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://my-proxy.example.com/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-custom-key'));
});
