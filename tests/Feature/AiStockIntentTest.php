<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AI\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function detectAiIntent(string $message, ?AiConversation $conversation = null): ?array
{
    $service = new AiService;
    $method = new ReflectionMethod($service, 'detectDirectToolCall');
    $method->setAccessible(true);

    return $method->invoke($service, $message, $conversation);
}

function toolRequiresConfirmation(string $toolName): bool
{
    $service = new AiService;
    $method = new ReflectionMethod($service, 'requiresConfirmation');
    $method->setAccessible(true);

    return $method->invoke($service, $toolName);
}

function makeCheckStockResult(AiConversation $conversation, array $products): void
{
    AiMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hasil dari tool check_stock: '.json_encode([
            'success' => true,
            'data' => ['products' => $products],
            'message' => 'ok',
        ]),
    ]);
}

it('mengenali perubahan stok massal dari hasil cek stok sebelumnya', function () {
    $conversation = AiConversation::create([
        'user_id' => User::factory()->create()->id,
        'title' => 'uji stok massal',
    ]);

    makeCheckStockResult($conversation, [
        ['id' => 1, 'name' => 'Produk Lama', 'stock' => '0', 'unit' => 'pcs'],
    ]);
    makeCheckStockResult($conversation, [
        ['id' => 7, 'name' => 'Teh Botol', 'stock' => '0', 'unit' => 'pcs'],
        ['id' => 8, 'name' => 'Kopi Sachet', 'stock' => '0', 'unit' => 'pcs'],
    ]);

    $result = detectAiIntent('tambah semua stok produk ini jadi 100', $conversation);

    expect($result['tool'])->toBe('update_stock_bulk')
        ->and($result['arguments']['set_to'])->toBe(100)
        ->and($result['arguments']['product_ids'])->toBe([7, 8])
        ->and($result['arguments']['product_names'])->toBe(['Teh Botol', 'Kopi Sachet']);
});

it('meminta klarifikasi saat daftar produk belum diketahui', function () {
    $result = detectAiIntent('tambah semua stok produk ini jadi 100');

    expect($result)->toHaveKey('text');
});

it('mengenali cek stok habis sebagai tool baca', function () {
    $result = detectAiIntent('cek stok yang habis');

    expect($result['tool'])->toBe('check_stock')
        ->and($result['arguments']['out_of_stock'])->toBeTrue();
});

it('mengenali produk terlaris mingguan', function () {
    $result = detectAiIntent('Apa saja produk terlaris minggu ini?');

    expect($result['tool'])->toBe('get_top_products')
        ->and($result['arguments']['period'])->toBe('this_week');
});

it('tidak mengambil alih perintah stok satu produk', function () {
    expect(detectAiIntent('tambah stok Bubuk Red Velvet 100 pcs'))->toBeNull();
});

it('mewajibkan konfirmasi untuk tool yang mengubah data', function () {
    expect(toolRequiresConfirmation('create_product'))->toBeTrue()
        ->and(toolRequiresConfirmation('update_product'))->toBeTrue()
        ->and(toolRequiresConfirmation('update_stock'))->toBeTrue()
        ->and(toolRequiresConfirmation('update_stock_bulk'))->toBeTrue()
        ->and(toolRequiresConfirmation('check_stock'))->toBeFalse()
        ->and(toolRequiresConfirmation('get_sales_summary'))->toBeFalse();
});

it('mengenali prediksi stok runway', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('predict_stock_runway');
})->with([
    'produk akan habis' => 'Produk mana yang akan habis dalam 14 hari ke depan?',
    'stok habis kapan' => 'Stok Teh Botol habis kapan?',
    'kapan restock' => 'Kapan saya harus restock semua produk?',
    'prediksi stok' => 'prediksi stok toko saya dong',
]);

it('meneruskan rentang hari peringatan ke prediksi runway', function () {
    $result = detectAiIntent('Produk mana yang akan habis dalam 14 hari ke depan?');

    expect($result['tool'])->toBe('predict_stock_runway')
        ->and($result['arguments']['warning_days'])->toBe(14);
});

it('mengenali pertanyaan performa dan margin sebagai insight penjualan', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('get_sales_insight');
})->with([
    'performa toko' => 'Gimana performa toko hari ini? Produk mana yang margin-nya paling besar?',
    'analisa toko' => 'analisa toko saya minggu ini',
    'kenapa penjualan turun' => 'kenapa penjualan bulan ini turun?',
]);

it('mengarahkan pertanyaan performa ke periode yang tepat', function () {
    expect(detectAiIntent('Gimana performa toko hari ini?')['arguments']['period'])->toBe('today')
        ->and(detectAiIntent('analisa toko saya minggu ini')['arguments']['period'])->toBe('this_week')
        ->and(detectAiIntent('analisa toko saya')['arguments']['period'])->toBe('this_week');
});

it('mengenali pertanyaan pelanggan sebagai customer insight', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('get_customer_insight');
})->with([
    'pelanggan terbaik' => 'Siapa pelanggan terbaik saya?',
    'pelanggan jarang' => 'Pelanggan mana yang mulai jarang belanja?',
    'poin loyalty' => 'berapa poin loyalty pelanggan saya?',
]);

it('menyertakan panduan pemilihan tool pada prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Stock\PredictStockRunwayTool,
        new \App\Tools\Reports\SalesInsightTool,
        new \App\Tools\Customers\CustomerInsightTool,
    ]);

    expect($prompt)->toContain('PANDUAN PILIH TOOL')
        ->toContain('predict_stock_runway')
        ->toContain('get_sales_insight')
        ->toContain('get_customer_insight');
});

it('menyertakan panduan analisa lintas cabang pada prompt tool', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Reports\SalesInsightTool,
    ]);

    expect($prompt)->toContain('semua cabang')
        ->toContain('scope "all"');
});

it('menambahkan scope all saat user meminta data semua cabang', function (string $message, string $expectedTool) {
    $result = detectAiIntent($message);

    expect($result['tool'])->toBe($expectedTool)
        ->and($result['arguments']['scope'])->toBe('all');
})->with([
    'analisa semua cabang' => ['analisa semua cabang bulan ini', 'get_sales_insight'],
    'penjualan semua outlet' => ['penjualan semua outlet bulan ini', 'get_sales_summary'],
    'ringkasan seluruh cabang' => ['ringkasan penjualan seluruh cabang minggu ini', 'get_sales_summary'],
    'produk terlaris semua outlet' => ['produk terlaris semua outlet', 'get_top_products'],
]);

it('tidak menambahkan scope saat user tidak meminta lintas cabang', function () {
    $result = detectAiIntent('analisa toko saya minggu ini');

    expect($result['tool'])->toBe('get_sales_insight')
        ->and($result['arguments'])->not->toHaveKey('scope');
});

it('membalas sapaan tanpa memanggil tool apa pun', function (string $message) {
    $result = detectAiIntent($message);

    expect($result)->toHaveKey('text')
        ->and($result)->not->toHaveKey('tool');
})->with([
    'apa kabar hari ini' => 'apa kabar hari ini ..',
    'halo' => 'halo ani',
    'selamat pagi' => 'selamat pagi',
    'terima kasih' => 'terima kasih banyak',
    'assalamualaikum' => 'assalamualaikum',
]);

it('tetap memproses permintaan data yang diawali sapaan', function () {
    expect(detectAiIntent('halo, cek stok yang rendah')['tool'])->toBe('check_stock')
        ->and(detectAiIntent('selamat pagi, penjualan hari ini berapa')['tool'])->toBe('get_sales_summary');
});

it('menyertakan aturan sapaan tanpa tool pada system prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildSystemPrompt();

    expect($prompt)->toContain('ATURAN SAPAAN')
        ->toContain('TANPA memanggil tool');
});

it('mengenali permintaan laporan laba rugi sebagai tool laba rugi', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('get_profit_report');
})->with([
    'laporan laba rugi' => 'laporan laba rugi bulan ini',
    'laba bersih' => 'berapa laba bersih saya kemarin',
    'cek laba rugi' => 'cek laba rugi minggu ini',
    'beban operasional' => 'beban operasional bulan ini berapa',
    'untung bersih' => 'hitung untung bersih saya tahun ini',
]);

it('meneruskan periode dan scope semua cabang ke laporan laba rugi', function () {
    expect(detectAiIntent('cek laba rugi kemarin')['arguments']['period'])->toBe('yesterday')
        ->and(detectAiIntent('laporan laba rugi minggu ini')['arguments']['period'])->toBe('this_week');

    $allBranches = detectAiIntent('laporan laba rugi semua cabang');

    expect($allBranches['tool'])->toBe('get_profit_report')
        ->and($allBranches['arguments']['scope'])->toBe('all');
});

it('mengenali perbandingan performa antar cabang', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('compare_branches');
})->with([
    'bandingkan antar cabang' => 'bandingkan penjualan antar cabang',
    'cabang mana yang' => 'cabang mana yang paling laris',
    'peringkat cabang' => 'peringkat cabang bulan ini',
    'cabang terlaris' => 'cabang terlaris minggu ini',
    'performa antar outlet' => 'performa antar outlet',
]);

it('meneruskan periode ke perbandingan cabang', function () {
    expect(detectAiIntent('cabang terlaris minggu ini')['arguments']['period'])->toBe('this_week')
        ->and(detectAiIntent('peringkat cabang bulan ini')['arguments']['period'])->toBe('this_month');
});

it('mengenali rekomendasi belanja dan restock', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('recommend_restock');
})->with([
    'rekomendasi restock' => 'rekomendasi restock minggu ini',
    'saran belanja' => 'saran belanja untuk restock',
    'daftar belanjaan' => 'buatkan daftar belanjaan',
    'harus beli apa' => 'saya harus beli apa minggu ini',
]);

it('mengenali daftar belanja supplier untuk purchase_suggestion', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('purchase_suggestion');
})->with([
    'daftar belanja supplier' => 'buatkan daftar belanja supplier',
    'belanja ke supplier' => 'belanja ke supplier apa aja',
    'order supplier' => 'siapkan order ke supplier',
    'total belanja per supplier' => 'total belanja per supplier berapa',
    'supplier mana yang perlu dihubungi' => 'supplier mana yang perlu dihubungi',
]);

it('mengarahkan cek laba kotor ke insight penjualan, bukan laporan laba rugi', function () {
    expect(detectAiIntent('cek laba kotor')['tool'])->toBe('get_sales_insight');
});

it('menyertakan panduan tool laba rugi, restock, dan perbandingan cabang pada prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Stock\PredictStockRunwayTool,
    ]);

    expect($prompt)->toContain('get_profit_report')
        ->toContain('recommend_restock')
        ->toContain('purchase_suggestion')
        ->toContain('compare_branches');
});

it('mengenali deteksi anomali transaksi untuk anti-fraud', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('detect_transaction_anomalies');
})->with([
    'transaksi mencurigakan' => 'cek transaksi mencurigakan bulan ini',
    'anomali transaksi' => 'ada anomali transaksi tidak?',
    'void berulang' => 'cek void berulang minggu ini',
    'diskon aneh' => 'ada diskon aneh kemarin?',
    'selisih kas' => 'selisih kas kemarin berapa',
    'audit kasir' => 'audit kasir bulan ini',
    'kasir nakal' => 'cek kasir nakal',
]);

it('meneruskan ambang diskon, void, dan scope semua cabang ke deteksi anomali', function () {
    $thresholds = detectAiIntent('cek transaksi mencurigakan diskon di atas 25% dan 5 kali void berulang');

    expect($thresholds['tool'])->toBe('detect_transaction_anomalies')
        ->and($thresholds['arguments']['discount_threshold_pct'])->toBe(25.0)
        ->and($thresholds['arguments']['void_threshold'])->toBe(5);

    $allBranches = detectAiIntent('transaksi mencurigakan semua cabang');

    expect($allBranches['tool'])->toBe('detect_transaction_anomalies')
        ->and($allBranches['arguments']['scope'])->toBe('all');
});

it('mengenali permintaan prediksi/forecast penjualan', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('forecast_sales');
})->with([
    'prediksi penjualan' => 'prediksi penjualan minggu depan',
    'perkiraan omzet' => 'perkiraan omzet bulan depan berapa ya?',
    'forecast' => 'forecast penjualan minggu ini dong',
    'akan laku berapa' => 'kopi sachet akan laku berapa besok?',
    'ramalan penjualan' => 'ramalan penjualan 14 hari ke depan',
]);

it('meneruskan rentang hari forecast dari permintaan prediksi penjualan', function () {
    expect(detectAiIntent('prediksi penjualan 30 hari ke depan')['arguments']['forecast_days'])->toBe(30)
        ->and(detectAiIntent('perkiraan omzet bulan depan')['arguments']['forecast_days'])->toBe(30)
        ->and(detectAiIntent('prediksi penjualan minggu depan')['arguments']['forecast_days'])->toBe(7);
});

it('menambahkan scope all pada forecast penjualan semua cabang', function () {
    $result = detectAiIntent('prediksi penjualan semua cabang minggu depan');

    expect($result['tool'])->toBe('forecast_sales')
        ->and($result['arguments']['scope'])->toBe('all');
});

it('mengenali permintaan rekomendasi harga jual', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('recommend_price');
})->with([
    'saran harga' => 'saran harga jual',
    'harga jual ideal' => 'harga jual yang ideal untuk Teh Botol',
    'berapa sebaiknya dijual' => 'berapa sebaiknya dijual produk ini?',
    'harga sesuai hpp' => 'harga sudah sesuai hpp?',
    'margin yang ideal' => 'margin yang ideal 25%',
]);

it('mengekstrak produk target dan margin dari permintaan rekomendasi harga', function () {
    $product = detectAiIntent('harga jual yang ideal untuk Teh Botol');
    $margin = detectAiIntent('margin yang ideal 25%');

    expect($product['arguments']['product_name'])->toBe('teh botol')
        ->and($product['arguments']['target_margin_pct'])->toBe(30.0)
        ->and($margin['arguments']['target_margin_pct'])->toBe(25.0);
});

it('menyertakan panduan tool anomali transaksi dan rekomendasi harga pada prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Reports\TransactionAnomalyTool,
        new \App\Tools\Products\PriceRecommendationTool,
    ]);

    expect($prompt)->toContain('detect_transaction_anomalies')
        ->toContain('recommend_price')
        ->toContain('selisih kas');
});

it('mengenali insight laba per produk dan bundling untuk product_profit_insight', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('product_profit_insight');
})->with([
    'produk bocor' => 'produk mana yang bocor',
    'produk boros' => 'produk apa yang boros',
    'cross-sell' => 'ada rekomendasi cross-sell?',
    'bundling' => 'produk apa yang cocok di-bundle',
    'sering dibeli bareng' => 'produk apa yang sering dibeli bareng',
    'insight laba per produk' => 'insight laba per produk dong',
    'produk rugi' => 'produk mana yang rugi',
]);

it('menambahkan scope semua cabang pada product_profit_insight', function () {
    $result = detectAiIntent('produk bocor semua cabang');

    expect($result['tool'])->toBe('product_profit_insight')
        ->and($result['arguments']['scope'])->toBe('all');
});

it('menyertakan panduan tool product_profit_insight pada prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Reports\ProductProfitInsightTool,
    ]);

    expect($prompt)->toContain('product_profit_insight')
        ->toContain('produk bocor')
        ->toContain('bundling');
});

it('mengenali pencarian pelanggan untuk search_customer', function (string $message) {
    expect(detectAiIntent($message)['tool'])->toBe('search_customer');
})->with([
    'cari pelanggan' => 'cari pelanggan Budi',
    'data pelanggan' => 'data pelanggan bernama Siti',
    'no telepon customer' => 'no telepon customer 08123456789',
    'customer bernama' => 'customer yang namanya Andi',
]);

it('mengekstrak query pencarian pelanggan dari pesan', function () {
    $result = detectAiIntent('cari pelanggan Budi Santoso');

    expect($result['tool'])->toBe('search_customer')
        ->and($result['arguments']['query'])->toContain('budi');
});

it('menyertakan panduan tool search_customer pada prompt', function () {
    $prompt = (new \App\Services\AI\AiPromptBuilder)->buildToolDescriptionsPrompt([
        new \App\Tools\Operational\SearchCustomerTool,
    ]);

    expect($prompt)->toContain('search_customer')
        ->toContain('cari pelanggan');
});
