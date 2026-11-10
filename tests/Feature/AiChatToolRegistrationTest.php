<?php

use App\Http\Controllers\Ai\AiChatController;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAiChatRegistrationStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Daftar Tool',
        'slug' => 'toko-daftar-tool-'.uniqid(),
        'is_active' => true,
    ]);
}

function registerAiChatTools(User $user): AiService
{
    $service = new AiService($user, $user->store);
    $method = new ReflectionMethod(AiChatController::class, 'registerTools');
    $method->setAccessible(true);
    $method->invoke(app(AiChatController::class), $service, $user);

    return $service;
}

it('mendaftarkan tool anomali transaksi dan rekomendasi harga pada jalur chat', function () {
    $store = createAiChatRegistrationStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $registry = registerAiChatTools($user)->getToolRegistry();

    expect($registry->has('detect_transaction_anomalies'))->toBeTrue()
        ->and($registry->has('recommend_price'))->toBeTrue();
});

it('mengeksekusi tool anomali dari registry chat tanpa error tool tidak ditemukan', function () {
    $store = createAiChatRegistrationStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $registry = registerAiChatTools($user)->getToolRegistry();
    $result = $registry->execute('detect_transaction_anomalies', ['period' => 'this_month'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->error)->toBeNull();
});

it('mendaftarkan tool search_customer pada jalur chat', function () {
    $store = createAiChatRegistrationStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);

    $registry = registerAiChatTools($user)->getToolRegistry();

    expect($registry->has('search_customer'))->toBeTrue();
});

it('mendaftarkan tool get_barber_commissions pada jalur chat', function () {
    $store = createAiChatRegistrationStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $registry = registerAiChatTools($user)->getToolRegistry();

    expect($registry->has('get_barber_commissions'))->toBeTrue();
});
