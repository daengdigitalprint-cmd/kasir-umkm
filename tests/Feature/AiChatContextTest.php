<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createContextStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Konteks',
        'slug' => 'toko-konteks-'.uniqid(),
        'is_active' => true,
    ]);
}

function createContextUser(Store $store): User
{
    return User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
}

function detectBulkIntent(AiService $service, string $message, ?AiConversation $conversation): ?array
{
    $method = new ReflectionMethod($service, 'detectBulkStockUpdate');
    $method->setAccessible(true);

    return $method->invoke($service, $message, $conversation);
}

/**
 * Parse payload event SSE dari output stream menjadi array assoc per tipe.
 *
 * @return array<int, array{type: string, data?: array, content?: string}>
 */
function parseContextSseEvents(string $streamed): array
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

it('meneruskan delta stream untuk pesan yang lewat ke provider AI', function () {
    $store = createContextStore();
    $user = createContextUser($store);

    $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Halo dari AI']]]])."\n\n"
        ."data: [DONE]\n\n";

    Http::fake(['*' => Http::response($sse, 200)]);

    $response = test()->actingAs($user)->post('/ai/chat/stream', [
        'message' => 'bisakah kamu jelaskan tips memulai usaha parfum?',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $events = parseContextSseEvents($response->streamedContent());
    $types = array_column($events, 'type');

    expect($types)->toContain('delta')
        ->and($types[array_key_last($types)])->toBe('done');

    $deltaText = collect($events)
        ->where('type', 'delta')
        ->pluck('content')
        ->implode('');
    expect($deltaText)->toBe('Halo dari AI');

    $result = collect($events)->firstWhere('type', 'result')['data'];
    expect($result['success'])->toBeTrue()
        ->and($result['message']['content'])->toBe('Halo dari AI');
});

it('mengabaikan conversation_id milik user lain dan memulai percakapan baru', function () {
    $store = createContextStore();
    $userA = createContextUser($store);
    $userB = createContextUser($store);

    $conversationA = AiConversation::create([
        'user_id' => $userA->id,
        'store_id' => $store->id,
        'title' => 'Percakapan A',
    ]);

    $response = test()->actingAs($userB)->post('/ai/chat', [
        'message' => 'cek stok yang habis',
        'conversation_id' => $conversationA->id,
    ]);

    $response->assertOk();

    expect($response->json('conversation_id'))->not->toBe($conversationA->id)
        ->and($conversationA->messages()->count())->toBe(0);
});

it('tidak memakai hasil cek stok yang sudah terlalu lama dalam percakapan', function () {
    $store = createContextStore();
    $user = createContextUser($store);

    $conversation = AiConversation::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'title' => 'Konteks basi',
    ]);

    // Hasil check_stock lama di awal percakapan
    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hasil dari tool check_stock: '.json_encode([
            'success' => true,
            'data' => ['products' => [['id' => 1, 'name' => 'Teh Botol']]],
        ]),
    ]);

    // Jauh lebih dari 10 pesan setelahnya — topik sudah berganti
    foreach (range(1, 15) as $i) {
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $i % 2 === 0 ? 'assistant' : 'user',
            'content' => "Pesan pengisi nomor {$i}",
        ]);
    }

    $result = detectBulkIntent(new AiService($user, $store), 'tambah semua stok produk ini jadi 100', $conversation);

    expect($result)->toHaveKey('text')
        ->and($result['text'])->toContain('belum tahu produk');
});

it('tetap memakai hasil cek stok terbaru sebagai konteks perintah massal', function () {
    $store = createContextStore();
    $user = createContextUser($store);

    $conversation = AiConversation::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'title' => 'Konteks segar',
    ]);

    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hasil dari tool check_stock: '.json_encode([
            'success' => true,
            'data' => ['products' => [
                ['id' => 7, 'name' => 'Bubuk Red Velvet'],
                ['id' => 8, 'name' => 'Gula Pasir'],
            ]],
        ]),
    ]);

    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Ada 2 produk dengan stok rendah.',
    ]);

    $result = detectBulkIntent(new AiService($user, $store), 'tambah semua stok produk ini jadi 100', $conversation);

    expect($result['tool'])->toBe('update_stock_bulk')
        ->and($result['arguments']['product_ids'])->toBe([7, 8])
        ->and($result['arguments']['set_to'])->toBe(100);
});
