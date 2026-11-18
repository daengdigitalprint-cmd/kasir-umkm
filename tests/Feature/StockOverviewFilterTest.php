<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function stockOverviewSetup(): User
{
    $store = Store::query()->create([
        'name' => 'Toko Stok',
        'slug' => 'toko-stok-'.uniqid(),
        'is_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $make = fn (string $name, float $stock, float $min) => Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 10000,
        'stock_quantity' => $stock,
        'min_stock_level' => $min,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $make('Aman', 50, 10);
    $make('Menipis', 3, 10);
    $make('Habis', 0, 0);

    return $user;
}

function stockOverviewNames($page): array
{
    return collect($page->toArray()['props']['products']['data'])->pluck('name')->sort()->values()->all();
}

it('memisahkan filter stok menipis, habis, dan perlu restock', function (string $query, array $expected) {
    $user = stockOverviewSetup();

    $this->actingAs($user)
        ->get('/stock-overview'.$query)
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($expected) {
            expect(stockOverviewNames($page))->toBe($expected);
            $page->where('stats.in_stock', 1)
                ->where('stats.low_stock', 1)
                ->where('stats.out_of_stock', 1);
        });
})->with([
    'menipis' => ['?stock=low', ['Menipis']],
    'tautan lama' => ['?low_stock=1', ['Menipis']],
    'habis' => ['?stock=out', ['Habis']],
    'perlu restock' => ['?stock=restock', ['Habis', 'Menipis']],
    'semua' => ['', ['Aman', 'Habis', 'Menipis']],
]);
