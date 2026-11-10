<?php

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\JournalEntry;
use App\Models\PreOrder;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreBackupService;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeBackupOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Test",
        'slug' => 'backup-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

/**
 * Seed seluruh jenis data yang di-export StoreBackupService.
 *
 * @return array{0: Product, 1: Product, 2: Customer, 3: Supplier}
 */
function seedBackupData(Store $store, User $user, Store $otherStore, User $otherUser): array
{
    $bundle = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Komponen',
        'sku' => 'SKU-KOMPONEN',
        'price' => 5000,
        'cost_price' => 2500,
        'stock_quantity' => 20,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Bundle',
        'sku' => 'SKU-BUNDLE',
        'barcode' => '8991234567890',
        'description' => 'Deskripsi produk',
        'category' => 'Makanan',
        'price' => 15000,
        'cost_price' => 7500,
        'stock_quantity' => 10,
        'min_stock_level' => 2,
        'unit' => 'pcs',
        'images' => ['produk.png'],
        'pharmacy_registration_no' => 'POM-123',
        'track_stock' => true,
        'is_bundle' => true,
        'has_variants' => true,
        'is_active' => true,
    ]);

    ProductVariant::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'name' => 'Ukuran Besar',
        'sku' => 'SKU-BUNDLE-BESAR',
        'price' => 18000,
        'cost_price' => 9000,
        'stock_quantity' => 5,
        'min_stock_level' => 1,
        'is_active' => true,
    ]);

    ProductBatch::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'batch_code' => 'BATCH-001',
        'quantity_remaining' => 3,
        'expiry_date' => '2027-06-30',
        'status' => 'available',
    ]);

    $product->bundleItems()->create([
        'bundle_product_id' => $product->id,
        'component_product_id' => $bundle->id,
        'quantity' => 2,
        'sort_order' => 0,
    ]);

    $customer = Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Pelanggan Uji',
        'email' => 'pelanggan@example.test',
        'phone' => '081234567890',
        'address' => 'Jl. Test No. 1',
    ]);

    $supplier = Supplier::query()->create([
        'store_id' => $store->id,
        'name' => 'Supplier Uji',
        'phone' => '081200000001',
        'email' => 'supplier@example.test',
        'address' => 'Jl. Supplier No. 2',
        'notes' => 'Catatan supplier',
    ]);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 15000,
        'tax_amount' => 0,
        'total_amount' => 15000,
        'amount_paid' => 20000,
        'change_amount' => 5000,
        'completed_at' => now(),
    ]);

    $transaction->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Produk Bundle',
        'product_sku' => 'SKU-BUNDLE',
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
    ]);

    $purchase = Purchase::query()->create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'purchase_number' => 'PO-TEST-001',
        'purchased_at' => now(),
        'notes' => 'Catatan pembelian',
        'dpp_amount' => 10000,
        'ppn_amount' => 1100,
        'total_amount' => 11100,
        'is_paid' => true,
        'amount_paid' => 11100,
    ]);

    $purchase->lines()->create([
        'product_id' => $product->id,
        'product_name_snapshot' => 'Produk Bundle',
        'sku_snapshot' => 'SKU-BUNDLE',
        'quantity' => 2,
        'unit_cost' => 5550,
        'batch_code' => 'BATCH-PO-1',
        'batch_expiry_date' => '2027-12-31',
    ]);

    $purchaseReturn = PurchaseReturn::query()->create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'returned_at' => now(),
        'notes' => 'Retur rusak',
    ]);

    $purchaseReturn->lines()->create([
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    StockMovement::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'user_id' => $user->id,
        'quantity_change' => 10,
        'type' => StockMovement::TYPE_PURCHASE,
        'balance_after' => 10,
        'notes' => 'Pembelian masuk',
    ]);

    $productLain = Product::query()->create([
        'store_id' => $otherStore->id,
        'name' => 'Produk Tujuan Transfer',
        'sku' => 'SKU-BUNDLE',
        'price' => 15000,
        'cost_price' => 7500,
        'stock_quantity' => 0,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $transfer = StockTransfer::query()->create([
        'from_store_id' => $store->id,
        'to_store_id' => $otherStore->id,
        'user_id' => $user->id,
        'status' => StockTransfer::STATUS_DRAFT,
        'notes' => 'Transfer uji',
    ]);

    StockTransferLine::query()->create([
        'stock_transfer_id' => $transfer->id,
        'from_product_id' => $product->id,
        'to_product_id' => $productLain->id,
        'quantity' => 2,
    ]);

    $preOrder = PreOrder::query()->create([
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'order_number' => 'PRE-TEST-001',
        'status' => 'open',
        'expected_pickup_at' => '2026-12-01',
        'deposit_received' => 20000,
        'estimated_total' => 50000,
        'notes' => 'Ambil sore',
    ]);

    $preOrder->lines()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 25000,
        'product_name' => 'Produk Bundle',
    ]);

    Discount::query()->create([
        'store_id' => $store->id,
        'name' => 'Diskon Uji',
        'code' => 'UJI10',
        'type' => 'percentage',
        'value' => 10,
        'minimum_amount' => 50000,
        'maximum_uses' => 100,
        'used_count' => 5,
        'starts_at' => now()->startOfDay(),
        'expires_at' => now()->addMonth(),
        'is_active' => true,
        'description' => 'Diskon pengujian',
    ]);

    CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opened_at' => now()->subHours(3),
        'closed_at' => now(),
        'opening_cash' => 100000,
        'expected_cash' => 250000,
        'closing_cash_counted' => 245000,
        'difference_amount' => -5000,
        'closing_notes' => 'Selisih kembalian',
    ]);

    $accounts = $store->chartOfAccounts()->orderBy('code')->get();
    $journal = JournalEntry::query()->create([
        'store_id' => $store->id,
        'entry_date' => now()->toDateString(),
        'reference_type' => 'test',
        'reference_id' => 1,
        'description' => 'Jurnal uji',
        'user_id' => $user->id,
    ]);

    $journal->lines()->create([
        'chart_of_account_id' => $accounts[0]->id,
        'debit' => 10000,
        'credit' => 0,
        'memo' => 'Debit',
    ]);

    $journal->lines()->create([
        'chart_of_account_id' => $accounts[1]->id,
        'debit' => 0,
        'credit' => 10000,
        'memo' => 'Kredit',
    ]);

    return [$product, $bundle, $customer, $supplier];
}

beforeEach(function () {
    Storage::fake('local');
    $this->service = new StoreBackupService;
});

it('menghasilkan backup lengkap dengan field sesuai skema database', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    [$product] = seedBackupData($store, $user, $otherStore, $otherUser);

    $backup = $this->service->generateBackup($store);

    // Semua seksi export wajib ada.
    expect($backup)->toHaveKeys([
        'backup_version', 'generated_at', 'store', 'products', 'product_categories',
        'customers', 'suppliers', 'transactions', 'purchases', 'purchase_returns',
        'stock_movements', 'stock_transfers', 'pre_orders', 'discounts',
        'cash_shifts', 'accounting',
    ]);

    // Produk: field kolom asli (bukan kolom yang tidak ada).
    $exportedProduct = collect($backup['products'])->firstWhere('sku', 'SKU-BUNDLE');
    expect($exportedProduct['stock_quantity'])->toEqual(10)
        ->and($exportedProduct['description'])->toBe('Deskripsi produk')
        ->and($exportedProduct['images'])->toBe(['produk.png'])
        ->and($exportedProduct['pharmacy_registration_no'])->toBe('POM-123')
        ->and($exportedProduct['variants'])->toHaveCount(1)
        ->and($exportedProduct['variants'][0]['stock_quantity'])->toEqual(5)
        ->and($exportedProduct['batches'])->toHaveCount(1)
        ->and($exportedProduct['batches'][0]['batch_code'])->toBe('BATCH-001')
        ->and($exportedProduct['batches'][0]['expiry_date'])->toBe('2027-06-30')
        ->and($exportedProduct['bundle_items'])->toHaveCount(1)
        ->and($exportedProduct['bundle_items'][0]['component_product_name'])->toBe('Produk Komponen');

    // Pembelian: purchased_at (bukan purchase_date) & batch_code.
    $exportedPurchase = $backup['purchases'][0];
    expect($exportedPurchase['purchased_at'])->not->toBeNull()
        ->and($exportedPurchase['purchased_at'])->not->toBe('')
        ->and($exportedPurchase['lines'][0]['batch_code'])->toBe('BATCH-PO-1')
        ->and($exportedPurchase['lines'][0]['batch_expiry_date'])->toBe('2027-12-31');

    // Retur: returned_at (bukan return_date).
    expect($backup['purchase_returns'][0]['returned_at'])->not->toBeNull();

    // Pergerakan stok: quantity_change (bukan quantity).
    expect($backup['stock_movements'][0]['quantity_change'])->toEqual(10)
        ->and($backup['stock_movements'][0]['type'])->toBe(StockMovement::TYPE_PURCHASE);

    // Transfer stok: memakai relasi fromProduct, bukan relasi product yang tidak ada.
    expect($backup['stock_transfers'])->toHaveCount(1)
        ->and($backup['stock_transfers'][0]['lines'][0]['product_name'])->toBe('Produk Bundle');

    // Pre-order: order_number (bukan pre_order_number).
    expect($backup['pre_orders'][0]['order_number'])->toBe('PRE-TEST-001')
        ->and($backup['pre_orders'][0]['lines'][0]['unit_price'])->toEqual(25000);

    // Shift kas: expected_cash (bukan closing_cash_expected).
    expect($backup['cash_shifts'][0]['expected_cash'])->toEqual(250000);

    // Jurnal: entry_date & description (bukan date & memo).
    $exportedJournal = $backup['accounting']['journal_entries'][0];
    expect($exportedJournal['entry_date'])->toBe(now()->toDateString())
        ->and($exportedJournal['description'])->toBe('Jurnal uji')
        ->and($exportedJournal['lines'])->toHaveCount(2);

    // Transaksi & diskon.
    expect($backup['transactions'][0]['customer_name'])->toBe('Pelanggan Uji')
        ->and($backup['transactions'][0]['items'])->toHaveCount(1)
        ->and($backup['discounts'][0]['minimum_amount'])->toEqual('50000.00')
        ->and($backup['discounts'][0]['maximum_uses'])->toEqual(100)
        ->and($backup['discounts'][0]['description'])->toBe('Diskon pengujian');
});

it('restore berjalan tanpa error Unknown column store_id pada tabel child', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    seedBackupData($store, $user, $otherStore, $otherUser);

    $history = $this->service->saveAndRecord($store, 'manual', $user->id);
    expect($history->status)->toBe('completed')
        ->and($history->records_count)->toBeGreaterThan(0)
        ->and(Storage::exists($history->filepath))->toBeTrue();

    $log = $this->service->restoreFromBackup($store, $history->filepath);

    // Regresi utama: 8 error "Unknown column 'store_id' in 'where clause'".
    expect($log['errors'])->toBeEmpty()
        ->and($log['restored'])->not->toBeEmpty();
});

it('restore memulihkan produk, pelanggan, supplier, dan diskon beserta store_id varian dan batch', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    seedBackupData($store, $user, $otherStore, $otherUser);

    $history = $this->service->saveAndRecord($store, 'manual', $user->id);
    $log = $this->service->restoreFromBackup($store, $history->filepath);

    expect($log['errors'])->toBeEmpty()
        ->and(Product::query()->where('store_id', $store->id)->count())->toBe(2);

    $product = Product::query()->where('store_id', $store->id)->where('sku', 'SKU-BUNDLE')->first();
    expect($product)->not->toBeNull()
        ->and($product->stock_quantity)->toEqual(10)
        ->and($product->images)->toBe(['produk.png'])
        ->and($product->pharmacy_registration_no)->toBe('POM-123');

    // store_id wajib diisi saat restore varian & batch (kolom tanpa default).
    expect(ProductVariant::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and(ProductBatch::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and($product->batches()->first()->batch_code)->toBe('BATCH-001');

    expect(Customer::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and(Customer::query()->where('store_id', $store->id)->first()->name)->toBe('Pelanggan Uji')
        ->and(Supplier::query()->where('store_id', $store->id)->count())->toBe(1);

    $discount = Discount::query()->where('store_id', $store->id)->first();
    expect($discount)->not->toBeNull()
        ->and($discount->minimum_amount)->toEqual('50000.00')
        ->and($discount->maximum_uses)->toEqual(100)
        ->and($discount->used_count)->toEqual(5)
        ->and($discount->description)->toBe('Diskon pengujian');
});

it('restore memulihkan transaksi, pembelian, stok, shift, jurnal, dan transfer beserta relasinya', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    [$product, , $customer, $supplier] = seedBackupData($store, $user, $otherStore, $otherUser);

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();
    $counts = fn () => [
        'transactions' => Transaction::query()->where('store_id', $store->id)->count(),
        'items' => DB::table('transaction_items')->where('transaction_id', $transaction->id)->count(),
        'purchases' => Purchase::query()->where('store_id', $store->id)->count(),
        'purchase_returns' => PurchaseReturn::query()->where('store_id', $store->id)->count(),
        'stock_movements' => StockMovement::query()->where('store_id', $store->id)->count(),
        'cash_shifts' => CashShift::query()->where('store_id', $store->id)->count(),
        'journals' => JournalEntry::query()->where('store_id', $store->id)->count(),
        'pre_orders' => PreOrder::query()->where('store_id', $store->id)->count(),
        'transfers' => StockTransfer::query()->where('from_store_id', $store->id)->count(),
        'transfer_lines' => StockTransferLine::query()->count(),
    ];
    $before = $counts();

    $history = $this->service->saveAndRecord($store, 'manual', $user->id);

    // Perubahan setelah backup: transaksi dihapus, data baru ditambahkan.
    $transaction->delete();
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Pelanggan Setelah Backup']);

    $log = $this->service->restoreFromBackup($store, $history->filepath, $user->id);

    expect($log['errors'])->toBeEmpty()
        ->and($counts())->toBe($before)
        ->and($before['transactions'])->toBe(1)
        ->and($before['items'])->toBe(1);

    // ID asli dipertahankan sehingga relasi tetap utuh.
    $restored = Transaction::query()->findOrFail($transaction->id);
    expect($restored->customer_id)->toBe($customer->id)
        ->and($restored->user_id)->toBe($user->id)
        ->and($restored->items()->first()->product_id)->toBe($product->id)
        ->and(Purchase::query()->where('store_id', $store->id)->first()->supplier_id)->toBe($supplier->id)
        ->and(Customer::query()->where('name', 'Pelanggan Setelah Backup')->exists())->toBeFalse()
        ->and(DB::table('product_bundle_items')->where('bundle_product_id', $product->id)->count())->toBe(1)
        ->and(DB::table('journal_lines')
            ->whereIn('journal_entry_id', JournalEntry::query()->where('store_id', $store->id)->select('id'))
            ->count())->toBeGreaterThanOrEqual(2);
});

it('restore ke toko lain memetakan ulang ID dan relasi', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    seedBackupData($store, $user, $otherStore, $otherUser);
    [$targetStore, $targetUser] = makeBackupOrg('Toko Tujuan');

    // Ubah kolom unik global agar tidak bentrok dengan toko sumber yang masih ada di DB yang sama.
    $backup = $this->service->generateBackup($store);
    $uniqueColumns = ['transaction_number', 'status_token', 'offline_sale_id', 'purchase_number', 'return_number', 'shift_number', 'transfer_number', 'referral_code'];
    foreach ($backup['tables'] as $table => $rows) {
        foreach ($rows as $i => $row) {
            foreach ($uniqueColumns as $column) {
                if (isset($row[$column])) {
                    $backup['tables'][$table][$i][$column] = $row[$column].'-B';
                }
            }
        }
    }
    Storage::put('backups/manual-target.json', json_encode($backup));

    $sourceProducts = Product::query()->where('store_id', $store->id)->count();
    $log = $this->service->restoreFromBackup($targetStore, 'backups/manual-target.json', $targetUser->id);

    expect($log['errors'])->toBeEmpty()
        ->and(Product::query()->where('store_id', $targetStore->id)->count())->toBe(2)
        ->and(Product::query()->where('store_id', $store->id)->count())->toBe($sourceProducts);

    $transaction = Transaction::query()->where('store_id', $targetStore->id)->firstOrFail();
    $item = $transaction->items()->firstOrFail();
    expect(Product::query()->find($item->product_id)->store_id)->toBe($targetStore->id)
        ->and(Customer::query()->find($transaction->customer_id)->store_id)->toBe($targetStore->id)
        ->and(StockMovement::query()->where('store_id', $targetStore->id)->count())
        ->toBe(StockMovement::query()->where('store_id', $store->id)->count());

    // Jurnal yang merujuk transaksi ikut dipetakan ke transaksi baru.
    $sourceTransaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();
    $sourceJournals = JournalEntry::query()
        ->where('reference_type', Transaction::class)
        ->where('reference_id', $sourceTransaction->id)
        ->count();
    expect(JournalEntry::query()
        ->where('store_id', $targetStore->id)
        ->where('reference_type', Transaction::class)
        ->where('reference_id', $transaction->id)
        ->count())->toBe($sourceJournals);

    // Akun jurnal menunjuk COA toko tujuan, bukan toko sumber.
    $lineAccountStores = DB::table('journal_lines')
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
        ->whereIn('journal_lines.journal_entry_id', JournalEntry::query()->where('store_id', $targetStore->id)->select('id'))
        ->distinct()
        ->pluck('chart_of_accounts.store_id')
        ->all();
    expect($lineAccountStores)->toBe([$targetStore->id]);
});

it('restore ke toko lain membuat ulang nomor dan token unik yang sudah dipakai toko sumber', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    seedBackupData($store, $user, $otherStore, $otherUser);
    [$targetStore, $targetUser] = makeBackupOrg('Toko Tujuan');

    Customer::query()->create(['store_id' => $targetStore->id, 'name' => 'Pelanggan Tujuan']);
    $history = $this->service->saveAndRecord($store, 'manual', $user->id);
    $sourceTransaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    // purchase_number 'PO-TEST-001' dan nomor/token transaksi unik global dan masih dipakai toko sumber.
    $log = $this->service->restoreFromBackup($targetStore, $history->filepath, $targetUser->id);

    $targetPurchase = Purchase::query()->where('store_id', $targetStore->id)->firstOrFail();
    $targetTransaction = Transaction::query()->where('store_id', $targetStore->id)->firstOrFail();

    expect($log['errors'])->toBeEmpty()
        ->and($targetPurchase->purchase_number)->toStartWith('PO-TEST-001-')
        ->and(Purchase::query()->where('purchase_number', 'PO-TEST-001')->value('store_id'))->toBe($store->id)
        ->and(Customer::query()->where('store_id', $targetStore->id)->pluck('name')->all())->toBe(['Pelanggan Uji']);

    if ($sourceTransaction->transaction_number) {
        expect($targetTransaction->transaction_number)->not->toBe($sourceTransaction->transaction_number);
    }
    if ($sourceTransaction->status_token) {
        expect($targetTransaction->status_token)->not->toBe($sourceTransaction->status_token)
            ->and(strlen($targetTransaction->status_token))->toBe(strlen($sourceTransaction->status_token));
    }
});

it('restore dan upload menolak backup format lama v1', function () {
    [$store, $user] = makeBackupOrg('Backup');
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Tetap Ada']);

    $legacy = ['backup_version' => '1.0.0', 'store' => ['id' => $store->id], 'products' => []];
    Storage::put('backups/legacy.json', json_encode($legacy));

    expect(fn () => $this->service->restoreFromBackup($store, 'backups/legacy.json', $user->id))
        ->toThrow(RuntimeException::class, 'format lama');
    expect(Customer::query()->where('store_id', $store->id)->count())->toBe(1);

    $this->actingAs($user)
        ->postJson('/settings/backup/upload', [
            'file' => UploadedFile::fake()->createWithContent('lama.json', json_encode($legacy)),
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('upload file backup eksternal tercatat di riwayat dan bisa di-restore', function () {
    [$store, $user] = makeBackupOrg('Backup');
    [$otherStore, $otherUser] = makeBackupOrg('Backup Lain');
    seedBackupData($store, $user, $otherStore, $otherUser);

    $json = json_encode($this->service->generateBackup($store));
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('backup-toko-saya.json', $json);

    $response = $this->actingAs($user)->postJson('/settings/backup/upload', ['file' => $file]);

    $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.trigger_type', 'upload');

    $history = \App\Models\BackupHistory::query()->findOrFail($response->json('data.id'));
    expect($history->store_id)->toBe($store->id)
        ->and($history->status)->toBe('completed')
        ->and($history->records_count)->toBeGreaterThan(0)
        ->and(Storage::exists($history->filepath))->toBeTrue();

    $this->actingAs($user)
        ->postJson('/settings/backup/restore', ['backup_id' => $history->id])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::query()->where('store_id', $store->id)->count())->toBe(2);
});

it('upload menolak file yang bukan backup valid', function () {
    [$store, $user] = makeBackupOrg('Backup');

    $this->actingAs($user)
        ->postJson('/settings/backup/upload', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('acak.json', '{"halo":"dunia"}'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->actingAs($user)
        ->postJson('/settings/backup/upload', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('backup.txt', 'bukan json'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(\App\Models\BackupHistory::query()->where('store_id', $store->id)->count())->toBe(0);
});
