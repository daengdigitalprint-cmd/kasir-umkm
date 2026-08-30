<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use App\Support\AdjustmentStockOperations;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test Tahap 7 — Jurnal akuntansi dan laporan turunan koreksi stok.
 */
function makeJournalStore(string $planSlug = 'bisnis'): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => $planSlug === 'starter' ? 0 : 99000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko Jurnal',
        'slug' => 'jurnal-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
        // Tes ini menguji posting jurnal koreksi stok → modul harus aktif.
        'settings' => ['accounting_enabled' => true],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeJournalProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Jurnal',
        'price' => 20000,
        'cost_price' => 12000,
        'average_cost' => 12000,
        'stock_quantity' => 100,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ], $attrs));
}

/**
 * Replika ringkas jalur posting: buat dokumen, terapkan selisih, lalu jurnal.
 */
function postJournalAdjustment(User $user, Store $store, string $reason, array $rows): StockAdjustment
{
    $document = DB::transaction(function () use ($user, $store, $reason, $rows) {
        $ids = collect($rows)->pluck('product_id')->sort()->values();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $document = StockAdjustment::create([
            'store_id' => $store->id,
            'type' => StockAdjustment::TYPE_ADJUSTMENT,
            'reason_code' => $reason,
            'status' => StockAdjustment::STATUS_DRAFT,
            'counted_at' => now(),
            'created_by' => $user->id,
        ]);

        $total = '0.00';

        foreach ($rows as $row) {
            $product = $products->get((int) $row['product_id']);
            AdjustmentStockOperations::assertAdjustable($store, $product);

            $systemQty = AdjustmentStockOperations::currentQuantity($product);
            $countedQty = ProductQuantity::normalizeStorageAmount($product, $row['counted_quantity']);
            $variance = AdjustmentStockOperations::variance($countedQty, $systemQty);

            $line = StockAdjustmentLine::create([
                'stock_adjustment_id' => $document->id,
                'product_id' => $product->id,
                'system_quantity' => $systemQty,
                'counted_quantity' => $countedQty,
                'variance_quantity' => $variance,
                'unit_cost' => InventoryCosting::currentAverageCost($product),
                'variance_value' => '0.00',
            ]);

            AdjustmentStockOperations::applyLine($store, $user, $product, $document, $line);
            $total = bcadd($total, (string) $line->fresh()->variance_value, 2);
        }

        $document->update([
            'status' => StockAdjustment::STATUS_POSTED,
            'posted_by' => $user->id,
            'posted_at' => now(),
            'total_variance_value' => $total,
        ]);

        return $document->fresh();
    });

    JournalPoster::postStockAdjustment($store, $document);

    return $document->fresh();
}

it('menjurnal selisih minus sebagai kerugian persediaan', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100, 'average_cost' => 12000]);

    // Hitung 90 dari 100 → rugi 10 x 12.000 = 120.000.
    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    $entry = JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->first();

    expect($entry)->not->toBeNull();

    $kerugian = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_KERUGIAN_PERSEDIAAN);
    $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);

    $debitKerugian = JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('chart_of_account_id', $kerugian->id)
        ->first();
    $kreditPersediaan = JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('chart_of_account_id', $persediaan->id)
        ->first();

    expect((float) $debitKerugian->debit)->toBe(120000.0);
    expect((float) $kreditPersediaan->credit)->toBe(120000.0);
});

it('menjurnal selisih plus sebagai surplus persediaan', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 40, 'average_cost' => 10000]);

    // Hitung 47 dari 40 → surplus 7 x 10.000 = 70.000.
    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_FOUND, [
        ['product_id' => $product->id, 'counted_quantity' => 47],
    ]);

    $entry = JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->first();

    $kerugian = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_BEBAN_KERUGIAN_PERSEDIAAN);
    $persediaan = ChartOfAccountsBootstrap::account($store, ChartOfAccountsBootstrap::CODE_PERSEDIAAN);

    // Surplus: persediaan didebit, beban kerugian dikredit (mengurangi beban).
    $debitPersediaan = JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('chart_of_account_id', $persediaan->id)
        ->first();
    $kreditKerugian = JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('chart_of_account_id', $kerugian->id)
        ->first();

    expect((float) $debitPersediaan->debit)->toBe(70000.0);
    expect((float) $kreditKerugian->credit)->toBe(70000.0);
});

it('tidak menjurnal saat selisih bersih nol', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 50]);

    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_CORRECTION, [
        ['product_id' => $product->id, 'counted_quantity' => 50],
    ]);

    $count = JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->count();

    expect($count)->toBe(0);
});

it('jurnal koreksi selalu seimbang debit dan kredit', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100, 'average_cost' => 8500]);

    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_DAMAGE, [
        ['product_id' => $product->id, 'counted_quantity' => 83],
    ]);

    $entry = JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->first();

    $sumDebit = (float) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('debit');
    $sumCredit = (float) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('credit');

    expect($sumDebit)->toBe($sumCredit);
    expect($sumDebit)->toBe(144500.0); // 17 x 8.500
});

it('tidak menjurnal dua kali untuk dokumen yang sama', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100]);

    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    // Panggil ulang; guard idempoten harus mencegah entri kedua.
    JournalPoster::postStockAdjustment($store, $doc->fresh());

    $count = JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->count();

    expect($count)->toBe(1);
});

it('menghapus jurnal saat dokumen dibatalkan', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100]);

    $doc = postJournalAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    expect(JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->count())->toBe(1);

    JournalPoster::reverseStockAdjustment($store, $doc);

    expect(JournalEntry::query()
        ->where('reference_type', StockAdjustment::class)
        ->where('reference_id', $doc->id)
        ->count())->toBe(0);
});

it('menampilkan laporan selisih stok dengan rekap nilai', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100, 'average_cost' => 12000]);

    postJournalAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    $response = $this->actingAs($user)->get('/reports/stock-variance');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Reports/StockVariance')
        ->where('summary.loss_value', -120000)
        ->where('summary.document_count', 1)
    );
});

it('menampilkan kartu stok dengan mutasi koreksi', function () {
    [$store, $user] = makeJournalStore();
    $product = makeJournalProduct($store, ['stock_quantity' => 100]);

    postJournalAdjustment($user, $store, StockAdjustment::REASON_DAMAGE, [
        ['product_id' => $product->id, 'counted_quantity' => 95],
    ]);

    $response = $this->actingAs($user)->get('/reports/stock-card?product_id='.$product->id);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Reports/StockCard')
        ->where('summary.total_out', 5)
    );
});

it('menolak laporan stok bagi toko paket starter', function () {
    [$store, $user] = makeJournalStore('starter');

    $this->actingAs($user)->get('/reports/stock-variance')->assertRedirect();
    $this->actingAs($user)->get('/reports/stock-card')->assertRedirect();
});
