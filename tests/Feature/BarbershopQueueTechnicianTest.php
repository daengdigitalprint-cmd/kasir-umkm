<?php

use App\Events\KitchenQueueUpdated;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\Queue;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleMenuPermissionSeeder;
use Illuminate\Support\Facades\Event;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

if (! function_exists('makeBarbershopPosStore')) {
    /**
     * Membuat toko barbershop aktif lengkap dengan kasir, teknisi, dan produk.
     *
     * @return array{0: Store, 1: User, 2: User, 3: Product}
     */
    function makeBarbershopPosStore(array $settings = []): array
    {
        $store = Store::query()->create([
            'name' => 'Barbershop(Pos)',
            'slug' => 'barbershop-pos-'.uniqid(),
            'business_type' => 'barbershop',
            'settings' => $settings,
            'is_active' => true,
            'is_subscription_active' => true,
        ]);

        $cashier = User::factory()->create([
            'store_id' => $store->id,
            'role' => 'cashier',
            'email_verified_at' => now(),
        ]);
        $store->members()->attach($cashier->id, ['role' => 'cashier']);

        $barber = User::factory()->create([
            'store_id' => $store->id,
            'role' => 'staff',
            'email_verified_at' => now(),
        ]);
        $store->members()->attach($barber->id, ['role' => 'barber']);

        CashShift::query()->create([
            'store_id' => $store->id,
            'user_id' => $cashier->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $product = Product::query()->create([
            'store_id' => $store->id,
            'name' => 'Potong Rambut',
            'price' => 50000,
            'stock_quantity' => 0,
            'track_stock' => false,
            'is_active' => true,
        ]);

        return [$store, $cashier, $barber, $product];
    }
}

beforeEach(function () {
    Event::fake([KitchenQueueUpdated::class]);
});

it('mengizinkan dua teknisi paralel tanpa bentrok nomor antrian', function () {
    [$store, $cashier, $barber] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    $barber2 = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'staff',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($barber2->id, ['role' => 'barber']);

    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    // Entri 1 → barber pertama
    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan 1',
            'barber_user_id' => $barber->id,
        ]);
    // Entri 2 → barber kedua
    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan 2',
            'barber_user_id' => $barber2->id,
        ]);
    // Entri 3 → umum (tanpa teknisi)
    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan 3',
        ]);

    expect(QueueEntry::query()->count())->toBe(3);

    $numbers = QueueEntry::query()->orderBy('number')->pluck('number')->all();
    expect($numbers)->toBe([1, 2, 3]);

    $entry1 = QueueEntry::query()->where('customer_name', 'Pelanggan 1')->first();
    expect((int) $entry1->barber_user_id)->toBe($barber->id);

    $entry2 = QueueEntry::query()->where('customer_name', 'Pelanggan 2')->first();
    expect((int) $entry2->barber_user_id)->toBe($barber2->id);

    $entry3 = QueueEntry::query()->where('customer_name', 'Pelanggan 3')->first();
    expect($entry3->barber_user_id)->toBeNull();
});

it('menolak teknisi yang bukan anggota toko (422)', function () {
    [$store, $cashier] = makeBarbershopPosStore(['queue_module_enabled' => true]);

    $outsiderStore = Store::query()->create([
        'name' => 'Barbershop Lain',
        'slug' => 'barbershop-lain-'.uniqid(),
        'business_type' => 'barbershop',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $outsider = User::factory()->create([
        'store_id' => $outsiderStore->id,
        'role' => 'staff',
        'email_verified_at' => now(),
    ]);
    $outsiderStore->members()->attach($outsider->id, ['role' => 'barber']);

    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $response = $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->postJson("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan',
            'barber_user_id' => $outsider->id,
        ]);

    $response->assertStatus(422);
    expect(QueueEntry::query()->count())->toBe(0);
});

it('mengabaikan barber_user_id pada toko non-barbershop dan simpan sebagai antrian umum', function () {
    $store = Store::query()->create([
        'name' => 'Toko Retail',
        'slug' => 'toko-retail-'.uniqid(),
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => ['queue_module_enabled' => true],
    ]);
    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);
    $barber = User::factory()->create(['role' => 'barber']);
    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    // Kirim barber_user_id meski toko bukan barbershop — harus disimpan sebagai umum (null).
    $response = $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan',
            'barber_user_id' => $barber->id,
        ]);
    $response->assertRedirect();

    $entry = QueueEntry::query()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->barber_user_id)->toBeNull();
});

it('antrian umum tetap berfungsi (tanpa teknisi)', function () {
    [$store, $cashier] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Walk-in',
        ]);

    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Walk-in 2',
        ]);

    expect(QueueEntry::query()->count())->toBe(2);
    expect(QueueEntry::query()->whereNull('barber_user_id')->count())->toBe(2);
});

it('role barber (member pivot) dapat mengakses papan antrian', function () {
    [$store, , $barber] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    (new RoleMenuPermissionSeeder)->run();
    $store->members()->syncWithoutDetaching([$barber->id => ['role' => 'barber']]);

    $response = $this->actingAs($barber)->get('/queues');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Queues/Index'));
});

it('role barber dapat menambah entri antrian melalui POST /queues/{queue}/entries', function () {
    [$store, , $barber] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    (new RoleMenuPermissionSeeder)->run();
    $store->members()->syncWithoutDetaching([$barber->id => ['role' => 'barber']]);

    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $this->actingAs($barber)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan Barber',
        ]);

    expect(QueueEntry::query()->count())->toBe(1);
});

it('transisi serving→completed dan waiting→cancelled diperbolehkan', function () {
    [$store, $cashier] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);
    $waiting = QueueEntry::query()->create([
        'store_id' => $store->id,
        'queue_id' => $queue->id,
        'number' => 1,
        'status' => 'waiting',
        'priority' => 0,
    ]);
    $serving = QueueEntry::query()->create([
        'store_id' => $store->id,
        'queue_id' => $queue->id,
        'number' => 2,
        'status' => 'serving',
        'called_at' => now(),
        'priority' => 0,
    ]);

    // waiting → cancelled
    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->patchJson("/queue-entries/{$waiting->id}", ['status' => 'cancelled'])
        ->assertOk();
    expect($waiting->fresh()->status)->toBe('cancelled');

    // serving → completed
    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->patchJson("/queue-entries/{$serving->id}", ['status' => 'completed'])
        ->assertOk();
    expect($serving->fresh()->status)->toBe('completed');
    expect($serving->fresh()->completed_at)->not->toBeNull();
});

it('menyimpan dan menampilkan teknisi + kursi pada entri antrian barbershop', function () {
    [$store, $cashier, $barber] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    $queue = Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $this->actingAs($cashier)->withSession(['_token' => 'csrf-token'])
        ->post("/queues/{$queue->id}/entries", [
            'customer_name' => 'Pelanggan',
            'barber_user_id' => $barber->id,
            'table_number' => '2',
        ]);

    $entry = QueueEntry::query()->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->barber_user_id)->toBe($barber->id)
        ->and($entry->table_number)->toBe('2');
});

it('prop Inertia papan dan detail memuat barbershopEnabled + barbers saat barbershop aktif', function () {
    [$store, $cashier] = makeBarbershopPosStore(['queue_module_enabled' => true]);
    Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $index = $this->actingAs($cashier)->get('/queues');
    $index->assertOk();
    $index->assertInertia(fn ($page) => $page
        ->where('barbershopEnabled', true)
        ->has('barbers'));

    $queue = Queue::query()->first();
    $show = $this->actingAs($cashier)->get("/queues/{$queue->id}");
    $show->assertOk();
    // Di mode barbershop, barbershopEnabled true; detail juga memuat barbers.
    $show->assertInertia(fn ($page) => $page
        ->where('barbershopEnabled', true)
        ->has('barbers'));
});

it('prop Inertia barbers kosong dan tidak mengekspos barber saat toko non-barbershop', function () {
    $store = Store::query()->create([
        'name' => 'Retail',
        'slug' => 'retail-'.uniqid(),
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => ['queue_module_enabled' => true],
    ]);
    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);
    Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Umum',
        'code' => 'Q001',
        'is_active' => true,
    ]);

    $response = $this->actingAs($cashier)->get('/queues');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('barbershopEnabled', false)
        ->where('barbers', []));
});
