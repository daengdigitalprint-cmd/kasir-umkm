<?php

/*
 * F8 — Saran tambahan menu (upsell): pasangan manual + "sering dibeli bersama",
 * dikirim ke kasir dan Scan Meja hanya saat modul dinyalakan.
 */

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UpsellRule;
use App\Models\User;
use App\Support\UpsellSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function upsellStore(array $settings = ['upsell_enabled' => true]): array
{
    $store = Store::query()->create([
        'name' => 'Warung Nasi',
        'slug' => 'nasi-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

function upsellProduct(Store $store, string $name, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => strtoupper(substr($name, 0, 3)).'-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 100,
        'track_stock' => true,
        'is_active' => true,
        'inventory_type' => 'sellable',
    ], $overrides));
}

/** Transaksi selesai berisi produk-produk tertentu. */
function upsellSale(Store $store, User $user, array $products, $when = null, string $status = 'completed'): void
{
    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TXN-UP-'.uniqid(),
        'status' => $status,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 0,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'amount_paid' => 0,
        'change_amount' => 0,
    ]);
    if ($when) {
        $transaction->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
    }
    foreach ($products as $product) {
        TransactionItem::query()->create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
        ]);
    }
}

it('mendahulukan pasangan manual lalu menambah yang sering dibeli bersama, maksimal 3', function () {
    [$store, $user] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $esTeh = upsellProduct($store, 'Es Teh');
    $kerupuk = upsellProduct($store, 'Kerupuk');
    $jeruk = upsellProduct($store, 'Es Jeruk');
    $sate = upsellProduct($store, 'Sate');
    $telur = upsellProduct($store, 'Telur');

    UpsellRule::create(['store_id' => $store->id, 'trigger_product_id' => $nasi->id, 'suggested_product_id' => $esTeh->id]);

    // Kerupuk paling sering (4x), lalu Es Jeruk (3x), Sate (3x), Telur hanya 2x (di bawah ambang).
    foreach (range(1, 4) as $i) {
        upsellSale($store, $user, [$nasi, $kerupuk]);
    }
    foreach (range(1, 3) as $i) {
        upsellSale($store, $user, [$nasi, $jeruk, $sate]);
    }
    foreach (range(1, 2) as $i) {
        upsellSale($store, $user, [$nasi, $telur]);
    }

    $map = UpsellSuggestions::build($store);

    expect($map[$nasi->id])->toHaveCount(3)
        ->and($map[$nasi->id][0])->toBe($esTeh->id)
        ->and($map[$nasi->id][1])->toBe($kerupuk->id)
        ->and($map[$nasi->id])->not->toContain($telur->id);
});

it('mengabaikan transaksi lama, batal, dan toko lain', function () {
    [$store, $user] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $kopi = upsellProduct($store, 'Kopi');

    foreach (range(1, 3) as $i) {
        upsellSale($store, $user, [$nasi, $kopi], now()->subDays(40));
        upsellSale($store, $user, [$nasi, $kopi], null, 'pending');
    }

    [$other, $otherUser] = upsellStore();
    $nasiLain = upsellProduct($other, 'Nasi Goreng');
    $kopiLain = upsellProduct($other, 'Kopi');
    foreach (range(1, 3) as $i) {
        upsellSale($other, $otherUser, [$nasiLain, $kopiLain]);
    }

    expect(UpsellSuggestions::build($store))->toBe([]);
});

it('tidak menyarankan produk nonaktif atau bahan baku', function () {
    [$store] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $mati = upsellProduct($store, 'Menu Lama', ['is_active' => false]);
    $bahan = upsellProduct($store, 'Beras', ['inventory_type' => 'raw_material']);
    UpsellRule::create(['store_id' => $store->id, 'trigger_product_id' => $nasi->id, 'suggested_product_id' => $mati->id]);
    UpsellRule::create(['store_id' => $store->id, 'trigger_product_id' => $nasi->id, 'suggested_product_id' => $bahan->id]);

    expect(UpsellSuggestions::build($store))->toBe([]);
});

it('mengirim peta saran ke kasir hanya bila modul aktif', function () {
    [$store, $user] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $esTeh = upsellProduct($store, 'Es Teh');
    UpsellRule::create(['store_id' => $store->id, 'trigger_product_id' => $nasi->id, 'suggested_product_id' => $esTeh->id]);

    $this->actingAs($user)->get('/pos')->assertOk()
        ->assertInertia(fn ($page) => $page->where("upsellMap.{$nasi->id}", [$esTeh->id]));

    [$offStore, $offUser] = upsellStore(['upsell_enabled' => false]);
    $this->actingAs($offUser)->get('/pos')->assertOk()
        ->assertInertia(fn ($page) => $page->where('upsellMap', []));
});

it('menyimpan, menonaktifkan, dan menghapus pasangan manual', function () {
    [$store, $user] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $esTeh = upsellProduct($store, 'Es Teh');
    $kerupuk = upsellProduct($store, 'Kerupuk');

    $this->actingAs($user)->post('/upsell-rules', [
        'trigger_product_id' => $nasi->id,
        'suggested_product_ids' => [$esTeh->id, $kerupuk->id],
    ])->assertSessionHasNoErrors();

    expect(UpsellRule::query()->where('trigger_product_id', $nasi->id)->pluck('suggested_product_id')->all())
        ->toBe([$esTeh->id, $kerupuk->id])
        ->and(UpsellSuggestions::map($store->fresh())[$nasi->id])->toBe([$esTeh->id, $kerupuk->id]);

    $rule = UpsellRule::query()->where('suggested_product_id', $esTeh->id)->sole();
    $this->actingAs($user)->patch("/upsell-rules/{$rule->id}", ['is_active' => false])->assertSessionHasNoErrors();
    // Cache peta saran dibuang saat aturan berubah.
    expect(UpsellSuggestions::map($store->fresh())[$nasi->id])->toBe([$kerupuk->id]);

    $this->actingAs($user)->delete("/upsell-rules/{$rule->id}")->assertSessionHasNoErrors();
    expect(UpsellRule::query()->count())->toBe(1);
});

it('menolak saran ke diri sendiri, lebih dari 3, dan produk toko lain', function () {
    [$store, $user] = upsellStore();
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $a = upsellProduct($store, 'A');
    $b = upsellProduct($store, 'B');
    $c = upsellProduct($store, 'C');
    $d = upsellProduct($store, 'D');
    [$other] = upsellStore();
    $foreign = upsellProduct($other, 'Asing');

    $this->actingAs($user)->post('/upsell-rules', ['trigger_product_id' => $nasi->id, 'suggested_product_ids' => [$nasi->id]])
        ->assertSessionHasErrors('suggested_product_ids.0');
    $this->actingAs($user)->post('/upsell-rules', ['trigger_product_id' => $nasi->id, 'suggested_product_ids' => [$a->id, $b->id, $c->id, $d->id]])
        ->assertSessionHasErrors('suggested_product_ids');
    $this->actingAs($user)->post('/upsell-rules', ['trigger_product_id' => $nasi->id, 'suggested_product_ids' => [$foreign->id]])
        ->assertSessionHasErrors('suggested_product_ids.0');

    // Sudah 2 saran → tambah 2 lagi melebihi batas 3.
    $this->actingAs($user)->post('/upsell-rules', ['trigger_product_id' => $nasi->id, 'suggested_product_ids' => [$a->id, $b->id]]);
    $this->actingAs($user)->post('/upsell-rules', ['trigger_product_id' => $nasi->id, 'suggested_product_ids' => [$c->id, $d->id]])
        ->assertSessionHasErrors('suggested_product_ids');

    expect(UpsellRule::query()->count())->toBe(2);
});

it('tidak bisa mengubah aturan milik toko lain', function () {
    [$store] = upsellStore();
    $rule = UpsellRule::create([
        'store_id' => $store->id,
        'trigger_product_id' => upsellProduct($store, 'Nasi')->id,
        'suggested_product_id' => upsellProduct($store, 'Teh')->id,
    ]);
    [, $intruder] = upsellStore();

    $this->actingAs($intruder)->delete("/upsell-rules/{$rule->id}")->assertNotFound();
    expect(UpsellRule::query()->count())->toBe(1);
});

it('saklar modul dan saran otomatis tersimpan di pengaturan toko', function () {
    [$store, $user] = upsellStore(['upsell_enabled' => false]);

    $this->actingAs($user)->post('/upsell-rules/settings', ['enabled' => true, 'auto_enabled' => false])->assertSessionHasNoErrors();

    $store->refresh();
    expect(UpsellSuggestions::enabled($store))->toBeTrue()
        ->and(UpsellSuggestions::autoEnabled($store))->toBeFalse();

    $this->actingAs($user)->get('/upsell-rules')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Upsell/Index')->where('enabled', true)->where('autoEnabled', false));
});

it('menu Scan Meja tamu menerima peta saran', function () {
    [$store] = upsellStore(['upsell_enabled' => true, 'scan_meja_enabled' => true]);
    $nasi = upsellProduct($store, 'Nasi Goreng');
    $esTeh = upsellProduct($store, 'Es Teh');
    UpsellRule::create(['store_id' => $store->id, 'trigger_product_id' => $nasi->id, 'suggested_product_id' => $esTeh->id]);
    $table = \App\Models\Table::query()->create([
        'store_id' => $store->id,
        'name' => 'Meja 1',
        'code' => 'M1-'.uniqid(),
        'slug' => 'meja-'.uniqid(),
        'is_active' => true,
    ]);

    $this->get("/m/{$table->slug}")->assertOk()->assertInertia(
        fn ($page) => $page->component('ScanMeja/Guest/Menu')->where("upsellMap.{$nasi->id}", [$esTeh->id])
    );
});
