<?php

use App\Models\AiAuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiService;
use App\Tools\Audit\ActionLogTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createActionLogStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Riwayat',
        'slug' => 'toko-riwayat-'.uniqid(),
        'is_active' => true,
    ]);
}

function createActionLogUser(Store $store): User
{
    return User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
}

function detectActionLogIntent(string $message): ?array
{
    $service = new AiService;
    $method = new ReflectionMethod($service, 'detectDirectToolCall');
    $method->setAccessible(true);

    return $method->invoke($service, $message, null);
}

it('mendeteksi pertanyaan riwayat aksi sebagai intent langsung', function (string $message) {
    $result = detectActionLogIntent($message);

    expect($result['tool'])->toBe('get_action_log');
})->with([
    'riwayat aksi' => 'riwayat aksi ai',
    'apa saja yang diubah' => 'apa saja yang kamu ubah',
    'lihat log' => 'lihat log aksi yang sudah dilakukan',
]);

it('tidak mencuri intent lain', function () {
    expect(detectActionLogIntent('cek stok yang habis')['tool'])->toBe('check_stock')
        ->and(detectActionLogIntent('produk terlaris bulan ini')['tool'])->toBe('get_top_products');
});

it('menampilkan aksi tulis dari audit log', function () {
    $store = createActionLogStore();
    $user = createActionLogUser($store);

    AiAuditLog::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'action' => 'tool_execution',
        'tool_name' => 'create_product',
        'parameters' => ['name' => 'Teh Botol', 'price' => 6000],
        'result' => ['success' => true, 'message' => 'Produk Teh Botol berhasil ditambahkan.'],
        'status' => 'success',
    ]);

    $result = (new ActionLogTool)->execute([], $user);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->data['total'])->toBe(1)
        ->and($result->data['actions'][0]['tool'])->toBe('create_product')
        ->and($result->data['actions'][0]['status'])->toBe('success')
        ->and($result->message)->toContain('create_product')
        ->and($result->message)->toContain('sukses')
        ->and($result->message)->toContain('Teh Botol berhasil ditambahkan');
});

it('menyembunyikan aksi baca secara default dan menampilkannya saat diminta', function () {
    $store = createActionLogStore();
    $user = createActionLogUser($store);

    AiAuditLog::create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'action' => 'tool_execution',
        'tool_name' => 'check_stock',
        'parameters' => [],
        'result' => ['success' => true, 'message' => '5 produk stok rendah'],
        'status' => 'success',
    ]);

    $default = (new ActionLogTool)->execute([], $user);
    $withReads = (new ActionLogTool)->execute(['include_reads' => true], $user);

    expect($default->data['total'])->toBe(0)
        ->and($default->message)->toContain('Belum ada aksi')
        ->and($withReads->data['total'])->toBe(1)
        ->and($withReads->data['actions'][0]['tool'])->toBe('check_stock');
});

it('mencatat penolakan konfirmasi ke audit log', function () {
    $store = createActionLogStore();
    $user = createActionLogUser($store);

    $confirmationId = (string) \Illuminate\Support\Str::uuid();

    \Illuminate\Support\Facades\Cache::put("ai_confirmations.{$confirmationId}", [
        'conversation_id' => 1,
        'tool' => 'create_product',
        'arguments' => ['name' => 'Produk Ditolak', 'price' => 1000],
    ], now()->addMinutes(30));

    $this->actingAs($user)->post('/ai/chat/reject', ['confirmation_id' => $confirmationId])
        ->assertSuccessful();

    $log = AiAuditLog::query()->where('action', 'confirmation_rejected')->first();

    expect($log)->not->toBeNull()
        ->and($log->store_id)->toBe($store->id)
        ->and($log->tool_name)->toBe('create_product')
        ->and($log->status)->toBe('cancelled')
        ->and($log->parameters['name'])->toBe('Produk Ditolak');
});
