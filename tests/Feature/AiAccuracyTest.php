<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiPromptBuilder;
use App\Services\AI\AiService;
use App\Tools\Reports\ProductProfitInsightTool;
use App\Tools\Reports\ProfitReportTool;
use App\Tools\Reports\SalesForecastTool;
use App\Tools\Reports\SalesInsightTool;
use App\Tools\Reports\SalesSummaryTool;
use App\Tools\Reports\TopProductsTool;
use App\Tools\Stock\CheckStockTool;
use App\Tools\Stock\PredictStockRunwayTool;
use App\Tools\Stock\RecommendRestockTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Tes regresi untuk penyebab "rekomendasi AI salah" yang ditemukan pada audit:
 * riwayat teracak, salah rute, zona waktu, definisi metrik, perbandingan periode
 * timpang, stok rendah yang melewatkan stok habis, dan bahan baku yang tak terhitung.
 */

afterEach(fn () => Carbon::setTestNow());

function accStore(array $overrides = []): Store
{
    return Store::query()->create(array_merge([
        'name' => 'Toko Akurasi',
        'slug' => 'toko-akurasi-'.uniqid(),
        'is_active' => true,
        'timezone' => 'Asia/Makassar',
    ], $overrides));
}

function accUser(Store $store): User
{
    return User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
}

function accProduct(Store $store, string $name, array $overrides = []): Product
{
    $product = Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 10000,
        'cost_price' => 6000,
        'stock_quantity' => 100,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));

    $product->forceFill(['created_at' => $overrides['created_at'] ?? now()->subDays(200)])->save();

    return $product;
}

/**
 * @param  array<string, mixed>  $tx
 * @param  array<string, mixed>  $item
 */
function accSale(Store $store, User $user, Product $product, float $quantity, $at, array $tx = [], array $item = []): Transaction
{
    $total = (float) $product->price * $quantity;

    $transaction = Transaction::create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-ACC-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'is_void' => false,
    ], $tx));

    $transaction->forceFill(['created_at' => $at])->save();

    TransactionItem::create(array_merge([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $total,
        'unit_cost_snapshot' => $product->cost_price,
        'total_cost_snapshot' => (float) $product->cost_price * $quantity,
        'gross_profit_snapshot' => $total - (float) $product->cost_price * $quantity,
    ], $item));

    return $transaction;
}

function accIntent(string $message, ?Store $store = null, ?User $user = null): ?array
{
    $service = new AiService($user, $store);
    $method = new ReflectionMethod($service, 'detectDirectToolCall');
    $method->setAccessible(true);

    return $method->invoke($service, $message, null);
}

// ---------------------------------------------------------------- riwayat

it('mengirim riwayat ke model sesuai urutan pesan walau created_at sama', function () {
    $store = accStore();
    $user = accUser($store);
    $conversation = AiConversation::create(['user_id' => $user->id, 'store_id' => $store->id, 'title' => 'Urutan']);
    $sameSecond = now()->startOfSecond();

    foreach ([['user', 'pertanyaan pertama'], ['user', 'Hasil dari tool get_sales_summary: {}'], ['assistant', 'jawaban pertama'], ['user', 'detailnya']] as [$role, $content]) {
        AiMessage::create(['conversation_id' => $conversation->id, 'role' => $role, 'content' => $content])
            ->forceFill(['created_at' => $sameSecond])->save();
    }

    $service = new AiService($user, $store);
    $method = new ReflectionMethod($service, 'buildMessages');
    $method->setAccessible(true);
    $messages = $method->invoke($service, $conversation);

    expect(array_column(array_slice($messages, 1), 'content'))->toBe([
        'pertanyaan pertama',
        'Hasil dari tool get_sales_summary: {}',
        'jawaban pertama',
        'detailnya',
    ]);
});

it('tidak mengawali riwayat dengan hasil tool yang kehilangan pemanggilnya', function () {
    $store = accStore();
    $user = accUser($store);
    $conversation = AiConversation::create(['user_id' => $user->id, 'store_id' => $store->id, 'title' => 'Potong']);

    AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'check_stock', 'arguments' => '{}']]]]);
    AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'tool', 'content' => '{}', 'tool_call_id' => 'c1']);

    for ($i = 0; $i < 23; $i++) {
        AiMessage::create(['conversation_id' => $conversation->id, 'role' => $i % 2 ? 'assistant' : 'user', 'content' => "pesan {$i}"]);
    }

    $service = new AiService($user, $store);
    $method = new ReflectionMethod($service, 'buildMessages');
    $method->setAccessible(true);
    $messages = $method->invoke($service, $conversation);

    expect($messages[1]['role'])->not->toBe('tool');
});

// ---------------------------------------------------------------- perutean

it('tidak salah mengarahkan pesan berisi "...s produk" ke insight bundling', function () {
    $intent = accIntent("Tambah produk baru data berikut : Nama Produk\tKategori\tSatuan\tStok NUTELLA PER PCS\tProduk Baru\tPcs\t30");

    expect($intent['tool'] ?? null)->not->toBe('product_profit_insight');
});

it('tetap mengenali permintaan bundling yang sebenarnya', function (string $message) {
    expect(accIntent($message)['tool'])->toBe('product_profit_insight');
})->with(['produk apa yang cocok untuk bundling', 'rekomendasi cross-sell', 'paket produk yang sering dibeli bareng']);

it('memakai periode yang disebut user, bukan periode bawaan', function (string $message, string $tool, string $period) {
    $intent = accIntent($message);

    expect($intent['tool'])->toBe($tool)
        ->and($intent['arguments']['period'])->toBe($period);
})->with([
    ['produk terlaris kemarin', 'get_top_products', 'yesterday'],
    ['produk terlaris minggu lalu', 'get_top_products', 'last_week'],
    ['analisa penjualan bulan lalu', 'get_sales_insight', 'last_month'],
    ['kenapa penjualan minggu lalu turun', 'get_sales_insight', 'last_week'],
    ['penjualan minggu lalu', 'get_sales_summary', 'last_week'],
    ['omzet bulan lalu berapa', 'get_sales_summary', 'last_month'],
    ['tampilkan daftar produk penjualan hari ini', 'get_top_products', 'today'],
    ['berapa laba bulan lalu', 'get_profit_report', 'last_month'],
]);

it('meneruskan kategori yang disebut user ke cek stok', function () {
    $store = accStore();
    $user = accUser($store);
    accProduct($store, 'Gula Pasir', ['category' => 'Bahan Baku', 'stock_quantity' => 0]);

    $intent = accIntent('iya cek yang stok rendah khusus bahan baku', $store, $user);

    expect($intent['tool'])->toBe('check_stock')
        ->and($intent['arguments']['category'])->toBe('Bahan Baku');
});

// ---------------------------------------------------------------- stok

it('menganggap stok habis sebagai stok rendah, bukan "semua stok aman"', function () {
    $store = accStore();
    $user = accUser($store);
    accProduct($store, 'Gula Pasir', ['category' => 'Bahan Baku', 'stock_quantity' => 0]);
    accProduct($store, 'Susu UHT', ['category' => 'Bahan Baku', 'stock_quantity' => 0]);
    accProduct($store, 'Kopi Jadi', ['category' => 'Minuman', 'stock_quantity' => 500]);

    $result = (new CheckStockTool)->execute(['category' => 'bahan baku'], $user);

    expect($result->data['total'])->toBe(2)
        ->and($result->data['out_of_stock_count'])->toBe(2)
        ->and(array_column($result->data['products'], 'name'))->toEqualCanonicalizing(['Gula Pasir', 'Susu UHT'])
        ->and($result->message)->toContain('semuanya sudah habis')
        ->and($result->message)->not->toContain('di atas 10');
});

it('memakai stok minimum tiap produk dan mengabaikan produk yang stoknya tidak dilacak', function () {
    $store = accStore();
    $user = accUser($store);
    accProduct($store, 'Plastik Cup', ['stock_quantity' => 100, 'min_stock_level' => 350]);
    accProduct($store, 'Sedotan', ['stock_quantity' => 40, 'min_stock_level' => 20]);
    accProduct($store, 'Es Teh Racikan', ['stock_quantity' => 0, 'track_stock' => false]);

    $result = (new CheckStockTool)->execute([], $user);

    expect(array_column($result->data['products'], 'name'))->toBe(['Plastik Cup']);
});

it('menghitung pemakaian bahan baku lewat resep saat memprediksi stok habis', function () {
    $store = accStore();
    $user = accUser($store);
    $bahan = accProduct($store, 'Bubuk Thai Tea', ['stock_quantity' => 300, 'unit' => 'gram', 'inventory_type' => 'raw_material']);

    // Bahan baku tidak pernah muncul di transaction_items: terpakai 100 gram/hari lewat penjualan minuman.
    foreach (range(1, 30) as $daysAgo) {
        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $bahan->id,
            'user_id' => $user->id,
            'quantity_change' => -100,
            'type' => StockMovement::TYPE_SALE,
            'balance_after' => 0,
        ])->forceFill(['created_at' => now()->subDays($daysAgo)->addHour()])->save();
    }

    $runway = (new PredictStockRunwayTool)->execute([], $user);
    $restock = (new RecommendRestockTool)->execute([], $user);

    expect($runway->data['items'][0]['name'])->toBe('Bubuk Thai Tea')
        ->and($runway->data['items'][0]['avg_per_day'])->toBe(100.0)
        ->and($runway->data['items'][0]['days_left'])->toBe(3)
        ->and($runway->message)->toContain('Bubuk Thai Tea')
        ->and($restock->data['items'][0]['name'])->toBe('Bubuk Thai Tea')
        ->and($restock->data['items'][0]['suggested_quantity'])->toBe(1100);
});

it('tidak mengencerkan laju jual produk baru ke seluruh jendela 30 hari', function () {
    $store = accStore();
    $user = accUser($store);
    $baru = accProduct($store, 'Menu Baru', ['stock_quantity' => 50, 'created_at' => now()->subDays(10)]);

    // 10 unit/hari selama 10 hari sejak diluncurkan.
    foreach (range(1, 10) as $daysAgo) {
        accSale($store, $user, $baru, 10, now()->subDays($daysAgo)->addHour());
    }

    $result = (new PredictStockRunwayTool)->execute([], $user);

    expect($result->data['items'][0]['avg_per_day'])->toBe(10.0)
        ->and($result->data['items'][0]['days_left'])->toBe(5);
});

it('menandai produk di bawah minimum yang tidak bergerak alih-alih menyuruh beli begitu saja', function () {
    $store = accStore();
    $user = accUser($store);
    accProduct($store, 'Barang Mati', ['stock_quantity' => 1, 'min_stock_level' => 5]);

    $result = (new RecommendRestockTool)->execute([], $user);

    expect($result->data['items'][0]['no_recent_sales'])->toBeTrue()
        ->and($result->message)->toContain('pastikan masih perlu distok');
});

// ---------------------------------------------------------------- zona waktu

it('menghitung "hari ini" menurut jam toko, bukan jam server', function () {
    // 14 Nov 18:00 UTC = 15 Nov 02:00 WITA.
    Carbon::setTestNow(Carbon::parse('2026-11-14 18:00:00', 'UTC'));

    $store = accStore(['timezone' => 'Asia/Makassar']);
    $user = accUser($store);
    $product = accProduct($store, 'Nasi Kuning');

    accSale($store, $user, $product, 1, Carbon::parse('2026-11-14 17:30:00', 'UTC')); // 15 Nov 01:30 WITA → hari ini
    accSale($store, $user, $product, 2, Carbon::parse('2026-11-14 15:00:00', 'UTC')); // 14 Nov 23:00 WITA → kemarin

    $today = (new SalesSummaryTool)->execute(['period' => 'today'], $user);
    $yesterday = (new SalesSummaryTool)->execute(['period' => 'yesterday'], $user);

    expect($today->data['total_transactions'])->toBe(1)
        ->and($today->data['total_sales_value'])->toBe(10000.0)
        ->and($today->data['period']['from'])->toBe('15/11/2026')
        ->and($yesterday->data['total_transactions'])->toBe(1)
        ->and($yesterday->data['total_sales_value'])->toBe(20000.0);
});

it('menyebut jam dan zona waktu toko pada prompt, bukan label WITA tetap', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-14 18:00:00', 'UTC'));

    $store = accStore(['timezone' => 'Asia/Jakarta']);
    $prompt = (new AiPromptBuilder(accUser($store), $store))->buildSystemPrompt();

    expect($prompt)->toContain('15/11/2026 01:00 (WIB)')
        ->and($prompt)->not->toContain('(WITA)');
});

// ---------------------------------------------------------------- definisi metrik

it('melaporkan pendapatan bersih tanpa pajak dan servis seperti laporan', function () {
    $store = accStore();
    $user = accUser($store);
    $product = accProduct($store, 'Ayam Geprek', ['price' => 100000, 'cost_price' => 60000]);

    accSale($store, $user, $product, 1, now()->subMinutes(5), [
        'subtotal' => 100000,
        'discount_amount' => 10000,
        'tax_amount' => 9900,
        'total_amount' => 99900,
    ]);

    $summary = (new SalesSummaryTool)->execute(['period' => 'today'], $user);
    $profit = (new ProfitReportTool)->execute(['period' => 'today'], $user);

    expect($summary->data['total_sales_value'])->toBe(90000.0)
        ->and($summary->data['collected_value'])->toBe(99900.0)
        ->and($profit->data['revenue'])->toBe(90000.0)
        ->and($profit->data['hpp'])->toBe(60000.0)
        ->and($profit->data['gross_profit'])->toBe(30000.0);
});

it('berterus terang saat harga modal belum tercatat alih-alih menganggap margin nol', function () {
    $store = accStore();
    $user = accUser($store);
    $adaModal = accProduct($store, 'Ada Modal', ['price' => 10000, 'cost_price' => 6000]);
    $tanpaModal = accProduct($store, 'Tanpa Modal', ['price' => 10000, 'cost_price' => 0]);

    accSale($store, $user, $adaModal, 5, now()->subMinutes(10));
    accSale($store, $user, $tanpaModal, 5, now()->subMinutes(5), [], [
        'unit_cost_snapshot' => null, 'total_cost_snapshot' => null, 'gross_profit_snapshot' => null,
    ]);

    $profit = (new ProfitReportTool)->execute(['period' => 'today'], $user);
    $insight = (new ProductProfitInsightTool)->execute(['period' => 'today'], $user);

    expect($profit->data['cost_coverage_pct'])->toBe(50.0)
        ->and($profit->data['covered_margin_pct'])->toBe(40.0)
        ->and($profit->data['margin_reliable'])->toBeFalse()
        ->and($profit->message)->toContain('harga modal baru tercatat untuk 50%')
        ->and(array_column($insight->data['leaking_products'], 'product_name'))->not->toContain('Tanpa Modal')
        ->and($insight->data['summary']['products_without_cost'])->toBe(1)
        ->and($insight->message)->toContain('belum punya harga modal');
});

it('tidak memberi peringatan margin tipis bila harga modal belum cukup tercatat', function () {
    $store = accStore();
    $user = accUser($store);
    $tanpaModal = accProduct($store, 'Tanpa Modal', ['cost_price' => 0]);

    accSale($store, $user, $tanpaModal, 5, now()->subMinutes(5), [], [
        'unit_cost_snapshot' => null, 'total_cost_snapshot' => null, 'gross_profit_snapshot' => null,
    ]);

    $result = (new SalesInsightTool)->execute(['period' => 'today'], $user);

    expect(implode(' ', $result->data['recommendations']))->not->toContain('Margin kotor tipis')
        ->and($result->message)->toContain('BELUM bisa dihitung');
});

// ---------------------------------------------------------------- perbandingan periode

it('membandingkan minggu berjalan dengan rentang yang setara, bukan seminggu penuh', function () {
    // Rabu 11 Nov 2026, 20:00 WITA.
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));

    $store = accStore();
    $user = accUser($store);
    $product = accProduct($store, 'Roti Bakar');

    // Minggu lalu: 10 unit tiap hari Senin–Minggu. Minggu ini: 10 unit Senin–Rabu.
    foreach (range(2, 8) as $day) {
        accSale($store, $user, $product, 10, Carbon::parse("2026-11-0{$day} 04:00:00", 'UTC'));
    }
    foreach ([9, 10, 11] as $day) {
        accSale($store, $user, $product, 10, Carbon::parse(sprintf('2026-11-%02d 04:00:00', $day), 'UTC'));
    }

    $result = (new SalesInsightTool)->execute(['period' => 'this_week'], $user);

    expect($result->data['current']['revenue'])->toBe(300000.0)
        ->and($result->data['previous']['revenue'])->toBe(300000.0)
        ->and($result->data['direction'])->toBe('stabil')
        ->and($result->data['period']['previous_from'])->toBe('02/11/2026')
        ->and($result->data['period']['previous_to'])->toBe('04/11/2026');
});

it('mengaitkan penurunan penjualan dengan stok yang habis', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'UTC'));

    $store = accStore();
    $user = accUser($store);
    $product = accProduct($store, 'Donat Kentang', ['stock_quantity' => 0]);

    foreach ([2, 3, 4] as $day) {
        accSale($store, $user, $product, 20, Carbon::parse("2026-11-0{$day} 04:00:00", 'UTC'));
    }
    accSale($store, $user, $product, 2, Carbon::parse('2026-11-09 04:00:00', 'UTC'));

    $result = (new SalesInsightTool)->execute(['period' => 'this_week'], $user);

    expect($result->data['direction'])->toBe('turun')
        ->and($result->data['recommendations'][0])->toContain('Donat Kentang')
        ->and($result->data['recommendations'][0])->toContain('habis')
        ->and($result->data['recommendations'][0])->toContain('60 → 2');
});

it('tidak memasukkan hari ini yang baru berjalan ke dasar prediksi', function () {
    // 14 Nov 2026 pukul 08:00 WITA: hari ini baru ada 1 penjualan.
    Carbon::setTestNow(Carbon::parse('2026-11-14 00:00:00', 'UTC'));

    $store = accStore();
    $user = accUser($store);
    $product = accProduct($store, 'Es Kopi');

    foreach (range(1, 28) as $daysAgo) {
        accSale($store, $user, $product, 10, now()->subDays($daysAgo)->addHours(3));
    }
    accSale($store, $user, $product, 1, now()->subMinutes(10));

    $result = (new SalesForecastTool)->execute(['forecast_days' => 7], $user);

    expect($result->data['total_forecast_qty'])->toBe(70.0)
        ->and($result->data['history_days_used'])->toBe(28);
});

it('menampilkan margin produk terlaris dan urutan produk kurang laku', function () {
    $store = accStore();
    $user = accUser($store);
    $laris = accProduct($store, 'Laris', ['price' => 10000, 'cost_price' => 7000]);
    $sepi = accProduct($store, 'Sepi', ['price' => 10000, 'cost_price' => 4000]);

    accSale($store, $user, $laris, 9, now()->subMinutes(20));
    accSale($store, $user, $sepi, 1, now()->subMinutes(10));

    $top = (new TopProductsTool)->execute(['period' => 'today'], $user);
    $bottom = (new TopProductsTool)->execute(['period' => 'today', 'order' => 'bottom'], $user);

    expect($top->data['products'][0]['product_name'])->toBe('Laris')
        ->and($top->data['products'][0]['margin_pct'])->toBe(30.0)
        ->and($bottom->data['products'][0]['product_name'])->toBe('Sepi');
});

// ---------------------------------------------------------------- periode

it('mengenali frasa periode bahasa Indonesia', function (string $text, ?string $expected) {
    expect(AiPeriod::detect($text))->toBe($expected);
})->with([
    ['penjualan kemarin', 'yesterday'],
    ['omzet minggu lalu', 'last_week'],
    ['laba bulan kemarin', 'last_month'],
    ['30 hari terakhir', 'last_30_days'],
    ['stok gula', null],
]);

// ---------------------------------------------------------------- model

it('membiarkan model memanggil tool berurutan dan memakai temperature rendah', function () {
    $store = accStore();
    $user = accUser($store);
    accProduct($store, 'Gula Pasir', ['stock_quantity' => 0]);

    $toolCall = fn (string $id, string $name, string $args) => ['choices' => [['message' => [
        'content' => null,
        'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args]]],
    ], 'finish_reason' => 'tool_calls']]];

    Http::fake(['*' => Http::sequence()
        ->push($toolCall('call_1', 'get_sales_summary', '{"period":"today"}'))
        ->push($toolCall('call_2', 'check_stock', '{"out_of_stock":true}'))
        ->push(['choices' => [['message' => ['content' => 'Penjualan hari ini Rp 0; Gula Pasir habis.'], 'finish_reason' => 'stop']]])]);

    $service = new AiService($user, $store);
    $service->registerTools([new SalesSummaryTool, new CheckStockTool]);

    $result = $service->chat('bagaimana kondisi toko saya dan apa yang harus saya lakukan?');

    expect($result['success'])->toBeTrue()
        ->and($result['message']['content'])->toBe('Penjualan hari ini Rp 0; Gula Pasir habis.')
        ->and(array_column($result['tool_results'], 'name'))->toBe(['get_sales_summary', 'check_stock']);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => (float) $request['temperature'] <= 0.3);
});

it('tidak pernah mengirim jawaban kosong atau pseudo tool-call ke user', function () {
    $store = accStore();
    $user = accUser($store);

    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '<tool_call>update_product<arg_key>stock</arg_key><arg_value>100</arg_value></tool_call>'], 'finish_reason' => 'stop']]])]);

    $service = new AiService($user, $store);
    $result = $service->chat('kamu bisa apa');

    expect($result['message']['content'])->not->toContain('<tool_call>')
        ->and($result['message']['content'])->toContain('Coba ulangi');
});
