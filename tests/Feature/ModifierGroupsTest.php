<?php

use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: Store, 1: User, 2: Product} */
function makeModifierGroupStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Kedai Uji Pilihan',
        'slug' => 'modifier-group-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $owner = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($owner->id, ['role' => 'admin']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'sku' => 'MG-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $owner, $product];
}

function makeSugarAndToppingGroups(Store $store): array
{
    $sugar = ModifierGroup::query()->create([
        'store_id' => $store->id,
        'name' => 'Gula',
        'selection' => 'single',
        'is_required' => true,
        'options' => [
            ['key' => 'normal', 'label' => 'Normal', 'price' => 0],
            ['key' => 'sedikit', 'label' => 'Sedikit', 'price' => 0],
        ],
    ]);
    $topping = ModifierGroup::query()->create([
        'store_id' => $store->id,
        'name' => 'Topping',
        'selection' => 'multiple',
        'is_required' => false,
        'options' => [
            ['key' => 'boba', 'label' => 'Boba', 'price' => 3000],
            ['key' => 'keju', 'label' => 'Keju', 'price' => 2000],
        ],
    ]);

    return [$sugar, $topping];
}

test('grup pilihan bisa dibuat, diubah, dan dihapus lewat endpoint', function () {
    [$store, $owner] = makeModifierGroupStore();

    $response = $this->actingAs($owner)->postJson('/product-option-groups', [
        'name' => 'Gula',
        'selection' => 'single',
        'is_required' => true,
        'options' => [
            ['label' => 'Normal', 'price' => 0],
            ['label' => 'Tanpa gula', 'price' => 0],
            ['label' => '   ', 'price' => 0],
        ],
    ])->assertCreated();

    $group = ModifierGroup::query()->where('store_id', $store->id)->sole();
    expect($group->options)->toHaveCount(2)
        ->and($group->options[1]['key'])->toBe('tanpa_gula')
        ->and($response->json('group.id'))->toBe($group->id);

    // Kunci lama dipertahankan saat label diubah, supaya keranjang terbuka tetap cocok.
    $this->actingAs($owner)->putJson("/product-option-groups/{$group->id}", [
        'name' => 'Level gula',
        'selection' => 'single',
        'options' => [
            ['key' => 'normal', 'label' => 'Manis normal', 'price' => 0],
        ],
    ])->assertOk();

    $group->refresh();
    expect($group->name)->toBe('Level gula')
        ->and($group->is_required)->toBeFalse()
        ->and($group->options)->toBe([['key' => 'normal', 'label' => 'Manis normal', 'price' => 0]]);

    $this->actingAs($owner)->postJson('/product-option-groups', [
        'name' => 'Kosong',
        'selection' => 'multiple',
        'options' => [['label' => '', 'price' => 0]],
    ])->assertStatus(422);

    $this->actingAs($owner)->delete("/product-option-groups/{$group->id}")->assertRedirect();
    expect(ModifierGroup::query()->count())->toBe(0);
});

test('grup pilihan toko lain tidak bisa diubah', function () {
    [, $owner] = makeModifierGroupStore();
    [$otherStore] = makeModifierGroupStore();
    [$sugar] = makeSugarAndToppingGroups($otherStore);

    $this->actingAs($owner)->putJson("/product-option-groups/{$sugar->id}", [
        'name' => 'Dibajak',
        'selection' => 'single',
        'options' => [['label' => 'X', 'price' => 0]],
    ])->assertNotFound();

    expect($sugar->fresh()->name)->toBe('Gula');
});

test('kasir menghitung harga pilihan grup dan menyimpan catatan item', function () {
    [$store, $owner, $product] = makeModifierGroupStore();
    [$sugar, $topping] = makeSugarAndToppingGroups($store);
    $product->modifierGroups()->sync([$sugar->id, $topping->id]);

    $this->actingAs($owner)->postJson('/pos/process-payment', [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'modifiers' => [
                ['key' => "g{$sugar->id}:sedikit"],
                // Pilihan kedua pada grup "pilih satu" diabaikan.
                ['key' => "g{$sugar->id}:normal"],
                ['key' => "g{$topping->id}:boba"],
                ['key' => "g{$topping->id}:keju"],
                // Kunci asing diabaikan.
                ['key' => 'g999:gratis'],
            ],
            'notes' => '  es   dipisah ',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 100000,
    ])->assertOk()->assertJsonPath('success', true);

    $item = Transaction::query()->where('store_id', $store->id)->sole()->items()->sole();

    expect((float) $item->unit_price)->toBe(15000.0)
        ->and((float) $item->total_price)->toBe(30000.0)
        ->and(collect($item->modifiers)->pluck('label')->all())->toBe([
            'Gula: Sedikit',
            'Topping: Boba',
            'Topping: Keju',
            'Catatan: es dipisah',
        ])
        ->and(collect($item->modifiers)->firstWhere('key', '__note')['note'])->toBe('es dipisah');
});

test('catatan item tersimpan untuk produk tanpa pilihan', function () {
    [$store, $owner, $product] = makeModifierGroupStore();

    $this->actingAs($owner)->postJson('/pos/process-payment', [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'notes' => 'jangan pakai sedotan',
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ])->assertOk()->assertJsonPath('success', true);

    $item = Transaction::query()->where('store_id', $store->id)->sole()->items()->sole();

    expect((float) $item->unit_price)->toBe(10000.0)
        ->and($item->modifiers)->toHaveCount(1)
        ->and($item->modifiers[0]['label'])->toBe('Catatan: jangan pakai sedotan');
});

test('form produk menempelkan grup pilihan milik toko saja', function () {
    [$store, $owner, $product] = makeModifierGroupStore();
    [$sugar, $topping] = makeSugarAndToppingGroups($store);
    [$otherStore] = makeModifierGroupStore();
    [$foreign] = makeSugarAndToppingGroups($otherStore);

    $payload = [
        'name' => $product->name,
        'sku' => $product->sku,
        'barcode' => '',
        'description' => '',
        'brand' => '',
        'tax_rate' => 0,
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 20,
        'min_stock_level' => 0,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
        'modifier_group_ids_json' => json_encode([$topping->id, $sugar->id, $foreign->id]),
    ];

    $this->actingAs($owner)->put("/products/{$product->id}", $payload)->assertSessionHasNoErrors();

    expect($product->modifierGroups()->pluck('modifier_groups.id')->sort()->values()->all())
        ->toBe(collect([$sugar->id, $topping->id])->sort()->values()->all());

    $this->actingAs($owner)->put("/products/{$product->id}", [...$payload, 'modifier_group_ids_json' => '[]'])
        ->assertSessionHasNoErrors();

    expect($product->modifierGroups()->count())->toBe(0);
});
