<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function createAiConfirmStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Konfirmasi',
        'slug' => 'toko-konfirmasi-'.uniqid(),
        'is_active' => true,
    ]);
}

function postAiConfirmStream(User $user, string $message): TestResponse
{
    return test()->actingAs($user)->post('/ai/chat/stream', ['message' => $message]);
}

/**
 * Parse payload event SSE dari output stream menjadi array assoc per tipe.
 *
 * @return array<int, array{type: string, data?: array}>
 */
function parseAiConfirmEvents(string $streamed): array
{
    $events = [];

    foreach (preg_split('/\n\n/', $streamed) as $chunk) {
        $chunk = trim($chunk);
        if (! str_starts_with($chunk, 'data:')) {
            continue;
        }

        $payload = json_decode(trim(substr($chunk, 5)), true);
        if (is_array($payload) && isset($payload['type'])) {
            $events[] = $payload;
        }
    }

    return $events;
}

it('mengeksekusi konfirmasi dari stream setelah konfirmasi disimpan di cache', function () {
    $store = createAiConfirmStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $streamed = postAiConfirmStream($user, 'tambah produk Teh Botol Konfirmasi harga 6000 modal 4000 stok 100')
        ->assertOk()
        ->streamedContent();

    $events = parseAiConfirmEvents($streamed);
    $confirmation = $events[0]['data']['confirmation'] ?? null;

    expect($confirmation)->not->toBeNull()
        ->and($confirmation['id'])->toBeUuid();

    // Konfirmasi disimpan di cache sehingga tetap tersedia di request berikutnya.
    expect(Cache::get("ai_confirmations.{$confirmation['id']}"))->not->toBeNull();

    $response = $this->actingAs($user)->post('/ai/chat/confirm', [
        'confirmation_id' => $confirmation['id'],
    ]);

    $response->assertSuccessful();

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('message.content'))->not->toBeEmpty()
        ->and(Product::query()
            ->where('store_id', $store->id)
            ->where('name', 'Teh Botol Konfirmasi')
            ->exists())->toBeTrue();

    // Konfirmasi bersifat sekali pakai.
    $this->actingAs($user)->post('/ai/chat/confirm', [
        'confirmation_id' => $confirmation['id'],
    ])->assertStatus(422);
});

it('menolak konfirmasi yang tidak ditemukan di cache', function () {
    $store = createAiConfirmStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->actingAs($user)->post('/ai/chat/confirm', [
        'confirmation_id' => (string) \Illuminate\Support\Str::uuid(),
    ])->assertStatus(422);
});
