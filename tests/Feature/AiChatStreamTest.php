<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function createStreamStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Stream',
        'slug' => 'toko-stream-'.uniqid(),
        'is_active' => true,
    ]);
}

/**
 * Parse payload event SSE dari output stream menjadi array assoc per tipe.
 *
 * @return array<int, array{type: string, data?: array, content?: string, message?: string}>
 */
function parseSseEvents(string $streamed): array
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

function streamEventsFor(TestResponse $response): array
{
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    return parseSseEvents($response->streamedContent());
}

function postChatStream(\Illuminate\Contracts\Auth\Authenticatable $user, string $message): TestResponse
{
    return test()->actingAs($user)->post('/ai/chat/stream', [
        'message' => $message,
    ]);
}

it('mengembalikan hasil direct intent melalui event stream', function () {
    $store = createStreamStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $events = streamEventsFor(postChatStream($user, 'cek stok yang habis'));

    $types = array_column($events, 'type');
    expect($types)->toBe(['result', 'done']);

    $result = $events[0]['data'];
    expect($result['success'])->toBeTrue()
        ->and($result['conversation_id'])->toBeInt()
        ->and($result['message']['role'])->toBe('assistant')
        ->and($result['message']['content'])->not->toBeEmpty();
});

it('mengembalikan konfirmasi dengan id tersimpan di cache', function () {
    $store = createStreamStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $events = streamEventsFor(postChatStream($user, 'tambah produk Teh Botol harga 6000 modal 4000 stok 100'));

    $types = array_column($events, 'type');
    expect($types)->toBe(['result', 'done']);

    $confirmation = $events[0]['data']['confirmation'];
    expect($confirmation['tool'])->toBe('create_product')
        ->and($confirmation['arguments']['name'])->toContain('Teh Botol')
        ->and($confirmation['id'])->toBeUuid();

    $stored = \Illuminate\Support\Facades\Cache::get("ai_confirmations.{$confirmation['id']}");
    expect($stored)->not->toBeNull()
        ->and($stored['tool'])->toBe('create_product');
});

it('membuat percakapan baru pada event stream', function () {
    $store = createStreamStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $response = postChatStream($user, 'cek stok yang habis');

    expect($response->baseResponse instanceof \Illuminate\Http\StreamedResponse || $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse)->toBeTrue(
        'Response: '.$response->getStatusCode().' '.$response->headers->get('Content-Type')
    );

    $response->streamedContent();

    expect(\App\Models\AiConversation::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(\App\Models\AiMessage::query()->where('role', 'user')->count())->toBeGreaterThanOrEqual(1)
        ->and(\App\Models\AiMessage::query()->where('role', 'assistant')->count())->toBeGreaterThanOrEqual(1);
});
