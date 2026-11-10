<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat toko dengan tipe usaha tertentu plus user berhak akses inventory.
 *
 * @return array{0: Store, 1: User}
 */
function makeCatalogStore(string $businessType): array
{
    $store = Store::query()->create([
        'name' => 'Toko '.ucfirst($businessType),
        'slug' => $businessType.'-catalog-'.uniqid(),
        'business_type' => $businessType,
        'settings' => [],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    return [$store, $admin];
}

function validServicePayload(): array
{
    return [
        'name' => 'Potong Rambut Premium',
        'price' => 75000,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'jasa',
        'inventory_type' => 'sellable',
        'track_stock' => false,
        'service_duration_minutes' => 50,
    ];
}

it('menyimpan durasi layanan pada produk jasa barbershop tanpa stock_movements', function () {
    [, $admin] = makeCatalogStore('barbershop');

    $response = $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/products', validServicePayload());

    $response->assertRedirect();

    $product = Product::query()->where('name', 'Potong Rambut Premium')->first();
    expect($product)->not->toBeNull()
        ->and($product->service_duration_minutes)->toBe(50)
        ->and($product->track_stock)->toBeFalse();

    expect(StockMovement::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('menolak durasi layanan nol atau negatif', function () {
    [, $admin] = makeCatalogStore('barbershop');

    foreach ([0, -15] as $duration) {
        $payload = validServicePayload();
        $payload['service_duration_minutes'] = $duration;

        $this->actingAs($admin)
            ->withSession(['_token' => 'csrf-token'])
            ->postJson('/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_duration_minutes']);
    }

    expect(Product::query()->where('name', 'Potong Rambut Premium')->exists())->toBeFalse();
});

it('mengabaikan durasi layanan pada toko non-barbershop', function () {
    [, $admin] = makeCatalogStore('retail');

    $payload = validServicePayload();
    $payload['service_duration_minutes'] = 50;

    $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/products', $payload)
        ->assertRedirect();

    $product = Product::query()->where('name', 'Potong Rambut Premium')->first();
    expect($product)->not->toBeNull()
        ->and($product->service_duration_minutes)->toBeNull();
});

it('menambahkan layanan contoh barbershop secara idempotent', function () {
    [$store, $admin] = makeCatalogStore('barbershop');

    $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/products/sample-services')
        ->assertRedirect()
        ->assertSessionHas('success');

    $countAfterFirst = $store->products()->count();
    expect($countAfterFirst)->toBeGreaterThan(0);

    $sample = $store->products()->where('name', 'Potong Rambut Pendek')->first();
    expect($sample)->not->toBeNull()
        ->and($sample->service_duration_minutes)->toBe(45)
        ->and($sample->track_stock)->toBeFalse()
        ->and($sample->unit)->toBe('jasa');

    expect(StockMovement::query()->where('store_id', $store->id)->exists())->toBeFalse();

    $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/products/sample-services')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($store->products()->count())->toBe($countAfterFirst);
});

it('menolak layanan contoh untuk toko non-barbershop', function () {
    [$store, $admin] = makeCatalogStore('fnb');

    $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/products/sample-services')
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($store->products()->count())->toBe(0);
});
