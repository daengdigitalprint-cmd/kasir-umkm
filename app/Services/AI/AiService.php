<?php

namespace App\Services\AI;

use App\Models\AiAuditLog;
use App\Models\AiConfiguration;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiResponse;
use App\Services\AI\Providers\OpenAiCompatibleProvider;
use App\Tools\ToolResult;

class AiService
{
    protected AiProviderInterface $provider;

    protected AiToolRegistry $toolRegistry;

    protected AiPromptBuilder $promptBuilder;

    protected ?User $user;

    protected ?Store $store;

    public function __construct(?User $user = null, ?Store $store = null)
    {
        $this->user = $user;
        $this->store = $store;
        $this->toolRegistry = new AiToolRegistry;
        $this->promptBuilder = new AiPromptBuilder($user, $store);
        $this->provider = $this->resolveProvider();
    }

    protected function resolveProvider(): AiProviderInterface
    {
        // Cari konfigurasi dari database (per store)
        $query = AiConfiguration::query()
            ->active()
            ->when($this->store, fn ($q) => $q->forStore($this->store?->id))
            ->orderByRaw('store_id IS NOT NULL DESC') // Prioritaskan yang spesifik store
            ->limit(1);

        $config = $query->first();

        if ($config) {
            return new OpenAiCompatibleProvider([
                'base_url' => $config->base_url,
                'model_id' => $config->model_id,
                'api_key' => $config->api_key,
                'max_tokens' => $config->max_tokens,
                'temperature' => $config->temperature,
                'supports_function_calling' => $config->supports_function_calling,
            ]);
        }

        // Fallback: pengaturan AI dari halaman Super Admin (tabel ai_settings)
        AiSetting::applyToRuntime();

        // Fallback terakhir ke config file (.env)
        $providerName = config('ai.default_provider', 'openai');
        $providerConfig = config("ai.providers.{$providerName}", config('ai.providers.openai'));

        return new OpenAiCompatibleProvider($providerConfig);
    }

    public function registerTool(\App\Tools\AiTool $tool): void
    {
        $this->toolRegistry->register($tool);
    }

    public function registerTools(array $tools): void
    {
        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }
    }

    public function chat(string $userMessage, ?AiConversation $conversation = null, ?callable $onDelta = null): array
    {
        // Buat atau gunakan conversation yang ada
        if (! $conversation) {
            $conversation = AiConversation::create([
                'user_id' => $this->user?->id,
                'store_id' => $this->store?->id,
                'title' => substr($userMessage, 0, 50),
            ]);
        }

        // Simpan pesan user
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Coba deteksi intent langsung dari teks user (tanpa panggil AI)
        $directToolCall = $this->detectDirectToolCall($userMessage, $conversation);

        if ($directToolCall) {
            if (isset($directToolCall['text'])) {
                return $this->respondDirectly($conversation, $directToolCall['text']);
            }

            return $this->handlePromptToolCall($conversation, $directToolCall);
        }

        // Bangun messages array untuk API
        $messages = $this->buildMessages($conversation);

        // Dapatkan tool definitions
        $tools = $this->toolRegistry->getToolDefinitions();

        // Cek apakah provider support function calling
        $useNativeTools = $this->provider instanceof OpenAiCompatibleProvider
            && $this->provider->supportsFunctionCalling()
            && ! empty($tools);

        if ($useNativeTools) {
            // Kirim ke AI dengan native function calling
            $response = $onDelta
                ? $this->provider->chatStream($messages, $tools, $onDelta)
                : $this->provider->chat($messages, $tools);
        } else {
            // Prompt-based: tambahkan tool descriptions ke system prompt
            $toolPrompt = $this->promptBuilder->buildToolDescriptionsPrompt(
                $this->toolRegistry->all()
            );
            if ($toolPrompt) {
                $messages[0]['content'] .= "\n\n".$toolPrompt;
            }
            $response = $onDelta
                ? $this->provider->chatStream($messages, [], $onDelta)
                : $this->provider->chat($messages);
        }

        if (! $response->isSuccess()) {
            return [
                'success' => false,
                'error' => $response->error,
                'conversation_id' => $conversation->id,
                'status' => 502,
            ];
        }

        // Jika ada native tool calls, proses sebelum simpan
        if ($useNativeTools && $response->hasToolCalls()) {
            $confirmation = $this->findConfirmationForToolCalls($response->getToolCalls());

            if ($confirmation) {
                // Jangan simpan tool call & jangan eksekusi apa pun sampai user menyetujui.
                return [
                    'success' => true,
                    'conversation_id' => $conversation->id,
                    'confirmation' => $confirmation,
                ];
            }

            $assistantMessage = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response->getContent(),
                'tool_calls' => $response->getToolCalls(),
                'tokens_used' => $response->tokensUsed,
            ]);

            return $this->handleToolCalls($conversation, $assistantMessage, $response, $onDelta);
        }

        // Jika prompt-based, cek apakah ada tool call di content SEBELUM simpan
        if (! $useNativeTools && ! empty($tools)) {
            $promptToolCall = $this->parsePromptToolCall($response->getContent());
            if ($promptToolCall) {
                return $this->handlePromptToolCall($conversation, $promptToolCall);
            }
        }

        // Respons biasa (bukan tool call) - simpan dan kirim ke user
        $assistantMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $response->getContent(),
            'tool_calls' => $response->getToolCalls(),
            'tokens_used' => $response->tokensUsed,
        ]);

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $response->getContent(),
                'timestamp' => $assistantMessage->created_at->format('H:i'),
            ],
        ];
    }

    /**
     * Deteksi intent langsung dari teks user tanpa perlu memanggil AI.
     * Mengembalikan array ['tool' => ..., 'arguments' => ...], ['text' => ...], atau null.
     *
     * @return array{tool: string, arguments: array}|array{text: string}|null
     */
    protected function detectDirectToolCall(string $message, ?AiConversation $conversation = null): ?array
    {
        $text = strtolower(trim($message));

        // --- Sapaan / basa-basi: balas langsung tanpa memanggil tool apa pun ---
        $smallTalk = $this->detectSmallTalk($text);
        if ($smallTalk !== null) {
            return ['text' => $smallTalk];
        }

        // Frasa yang meminta agregasi lintas cabang (multi-outlet)
        $allBranches = preg_match('/(?:semua|seluruh)\s*(?:cabang|outlet|toko)|(?:antarcabang|antar\s*cabang)|lintas\s*(?:cabang|outlet)/', $text) === 1;

        // --- Laporan laba rugi (pendapatan - HPP - beban operasional = laba bersih) ---
        if (preg_match('/laporan\s+(?:laba|rugi|keuangan|profit)|laba\s+(?:bersih|rugi)|untung\s+(?:bersih|kotor)|rugi\s+bersih|beban\s+operasional|biaya\s+operasional|berapa\s+(?:laba|untung|rugi)|(?:cek|lihat|tampilkan|hitung)\s+(?:laba|untung|rugi)(?!\s+kotor)|(?:laba|untung|rugi)\s+(?:saya|kita|usaha|bisnis|toko)?\s*(?:hari\s+ini|kemarin|minggu|bulan|tahun)/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'kemarin') => 'yesterday',
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'minggu lalu') => 'last_week',
                str_contains($text, 'bulan lalu') => 'last_month',
                str_contains($text, 'minggu') => 'this_week',
                str_contains($text, 'bulan') => 'this_month',
                str_contains($text, 'tahun') => 'this_year',
                default => 'this_month',
            }];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'get_profit_report', 'arguments' => $arguments];
        }

        // --- Prediksi/forecast penjualan (proyeksi omzet masa depan) ---
        if (preg_match('/(?:prediksi|perkiraan|proyeksi|ramalan|forecast)\s+(?:penjualan|omzet|omset|laku|terjual|permintaan|demand)|(?:penjualan|omzet|omset|omset)\s+(?:akan|bakal|mungkin)\s+(?:laku|terjual|berapa|naik|turun)|\bforecast\b|(?:akan|bakal|mungkin)\s+(?:laku|terjual)\s+berapa|(?:penjualan|omset|omzet|laku)\s+(?:minggu|bulan)\s+depan|(?:minggu|bulan)\s+depan\s+(?:laku|terjual|penjualan|omset|omzet|berapa)/', $text)) {
            $arguments = ['forecast_days' => match (true) {
                (bool) preg_match('/(\d+)\s*(?:hari|harian)/', $text, $m) => (int) $m[1],
                str_contains($text, 'bulan depan') => 30,
                default => 7,
            }];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'forecast_sales', 'arguments' => $arguments];
        }

        // --- Perbandingan performa antar cabang/outlet (multi-cabang) ---
        if (preg_match('/(?:banding(?:kan|in)?|perbandingan|komparasi)\s+(?:performa\s+|penjualan\s+|kinerja\s+|hasil\s+)?(?:antar\s+|antara\s+|lintas\s+|sesama\s+)?(?:cabang|outlet)|(?:cabang|outlet)\s+mana\s+yang|(?:ranking|peringkat|urutan)\s+(?:cabang|outlet)|(?:cabang|outlet)\s+(?:terbaik|terburuk|terlaris|tersepi|paling\s+(?:laris|untung|besar|ramai|sepi))|(?:performa|kinerja|penjualan|hasil)\s+(?:antar|antara|lintas|sesama)\s+(?:cabang|outlet)/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'kemarin') => 'yesterday',
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'minggu lalu') => 'last_week',
                str_contains($text, 'bulan lalu') => 'last_month',
                str_contains($text, 'minggu') => 'this_week',
                str_contains($text, 'bulan') => 'this_month',
                str_contains($text, 'tahun') => 'this_year',
                default => 'this_month',
            }];

            return ['tool' => 'compare_branches', 'arguments' => $arguments];
        }

        // --- Deteksi anomali transaksi / anti-fraud kasir ---
        if (preg_match('/transaksi\s+(?:yang\s+)?(?:mencurigakan|aneh|janggal|ganjil)|\b(?:anomali|fraud|kecurangan)\b|anti[\s-]*fraud|void\s+(?:berulang|banyak|terus|beruntun)|(?:diskon)\s+(?:aneh|tidak\s+wajar|besar|gede|berlebihan|gila|anehnya)|selisih\s+kas|audit\s+(?:kasir|transaksi|penjualan)|kasir\s+(?:nakal|curang)/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'kemarin') => 'yesterday',
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'minggu lalu') => 'last_week',
                str_contains($text, 'bulan lalu') => 'last_month',
                str_contains($text, 'minggu') => 'this_week',
                str_contains($text, 'bulan') => 'this_month',
                str_contains($text, 'tahun') => 'this_year',
                default => 'this_month',
            }];

            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:%|persen)/', $text, $m)) {
                $arguments['discount_threshold_pct'] = (float) str_replace(',', '.', $m[1]);
            }

            if (preg_match('/(\d+)\s*(?:kali|x)\s*(?:void|batal)/', $text, $m)) {
                $arguments['void_threshold'] = (int) $m[1];
            }

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'detect_transaction_anomalies', 'arguments' => $arguments];
        }

        // --- Rekomendasi harga jual (HPP + margin target + harga pasar) ---
        if (preg_match('/(?:saran|rekomendasi|anjuran)(?:kan|in)?\s+(?:untuk\s+)?(?:harga|penetapan\s+harga)|harga\s+jual\s+(?:yang\s+)?(?:ideal|tepat|pas|baik|bagus)|berapa\s+(?:sebaiknya|seharusnya|enaknya|pasnya|harusnya)\s+(?:aku\s+|saya\s+|kita\s+)?(?:di)?jual|harga\s+(?:sudah\s+)?sesuai\s+(?:dengan\s+)?(?:hpp|harga\s+pokok|modal)|margin\s+(?:yang\s+)?(?:ideal|tepat|pas|bagus)/', $text)) {
            $arguments = ['target_margin_pct' => match (true) {
                (bool) preg_match('/(\d+(?:[.,]\d+)?)\s*(?:%|persen)/', $text, $m) => (float) str_replace(',', '.', $m[1]),
                default => 30.0,
            }];

            if (preg_match('/(?:untuk|buat|bagi)\s+(?:produk\s+)?([a-z0-9][a-z0-9\s&.\'\-]{1,50})$/i', $text, $m)) {
                $name = trim(preg_replace('/\s+(?:dong|ya|yuk|deh|nih|aja|kak|bang|yaa)\s*$/i', '', trim($m[1])));

                if ($name !== '' && ! preg_match('/%|persen|margin|harga|hpp/', $name)) {
                    $arguments['product_name'] = $name;
                }
            }

            return ['tool' => 'recommend_price', 'arguments' => $arguments];
        }

        // --- Insight penjualan & margin (analisa/performa bisnis, bukan ringkasan angka) ---
        if (preg_match('/(?:kenapa|kok|mengapa)\s+(?:penjualan|omset|omzet|usaha|bisnis)|(?:analisa|analisis|performa|kinerja|evaluasi)\s+(?:toko|penjualan|usaha|bisnis)|(?:analisa|analisis)\s+(?:semua\s*)?(?:cabang|outlet)|(?:produk|barang)\s+mana\s+yang\s+(?:margin|laba|profit)|\bmargin\b|laba\s+kotor/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'bulan') => 'this_month',
                str_contains($text, 'tahun') => 'this_year',
                default => 'this_week',
            }];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'get_sales_insight', 'arguments' => $arguments];
        }

        // --- Cari pelanggan berdasarkan nama/telepon/email ---
        if (preg_match('/(?:cari|carikan|temukan|cek|lihat|tampilkan)\s+(?:data\s+)?(?:pelanggan|customer)\s+(?:yang\s+)?(?:namanya\s+)?([a-z0-9][a-z0-9\s@.\'\-+]{1,50})|(?:pelanggan|customer)\s+(?:yang\s+)?(?:namanya\s+|bernama\s+)([a-z0-9][a-z0-9\s@.\'\-+]{1,50})|(?:no|nomor|telp|telepon|hp|wa|whatsapp)\s+(?:pelanggan|customer)\s*([0-9+\s\-]{4,20})|(?:cek|cari)\s+(?:no|nomor|telp|telepon|hp)\s*([0-9+\s\-]{4,20})/i', $text, $m)) {
            $query = trim($m[1] ?: $m[2] ?: $m[3] ?: $m[4]);

            return $query !== '' ? ['tool' => 'search_customer', 'arguments' => ['query' => $query]] : null;
        }

        // --- Insight pelanggan ---
        if (preg_match('/(?:siapa|cek|lihat|tampilkan|daftar)\s+(?:pelanggan|customer)|(?:pelanggan|customer)\b[^.?!]*?(?:terbaik|terbanyak|jarang|churn|hilang|paling\s+(?:sering|banyak|setia|berharga))|poin\s+loyal|loyalitas|loyalty/', $text)) {
            return ['tool' => 'get_customer_insight', 'arguments' => []];
        }

        // --- Prediksi stok runway (kapan stok habis / kapan harus restock) ---
        if (preg_match('/akan\s+(?:habis|kehabisan)|habis\s+(?:kapan|dalam)|berapa\s+lama\s+lagi|(?:prediksi|perkiraan)\s+(?:stok|persediaan)|(?:kapan|kapankah)\s+[^.?!]*\s(?:habis|kehabisan|restock|restok)|(?:jadwal|waktu)\s+(?:restock|restok)|(?:produk|barang)\s+mana\s+yang\s+(?:akan\s+)?(?:habis|kehabisan)/', $text)) {
            $arguments = [];

            if (preg_match('/(\d+)\s*hari/', $text, $m)) {
                $arguments['warning_days'] = (int) $m[1];
            }

            return ['tool' => 'predict_stock_runway', 'arguments' => $arguments];
        }

        // --- Ringkasan penjualan semua cabang (sebelum cabang per periode tunggal) ---
        if ($allBranches && preg_match('/(?:penjualan|omset|omzet|laporan\s+penjualan)/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'kemarin') => 'yesterday',
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'bulan') => 'this_month',
                str_contains($text, 'minggu') => 'this_week',
                default => 'this_month',
            }, 'scope' => 'all'];

            return ['tool' => 'get_sales_summary', 'arguments' => $arguments];
        }

        // --- Penjualan hari ini / ringkasan penjualan ---
        if (preg_match('/(?:penjualan|omset|omzet|laporan\s+penjualan)\s+(?:hari\s+ini|hari\s+ini)/', $text)) {
            return ['tool' => 'get_sales_summary', 'arguments' => ['period' => 'today']];
        }
        if (preg_match('/(?:penjualan|omset|omzet|laporan\s+penjualan)\s+(?:kemarin)/', $text)) {
            return ['tool' => 'get_sales_summary', 'arguments' => ['period' => 'yesterday']];
        }
        if (preg_match('/(?:penjualan|omset|omzet|laporan\s+penjualan)\s+(?:bulan\s+ini)/', $text)) {
            return ['tool' => 'get_sales_summary', 'arguments' => ['period' => 'this_month']];
        }
        if (preg_match('/(?:penjualan|omset|omzet|laporan\s+penjualan)\s+(?:minggu\s+ini)/', $text)) {
            return ['tool' => 'get_sales_summary', 'arguments' => ['period' => 'this_week']];
        }

        // --- Riwayat aksi AI ---
        if (preg_match('/(?:riwayat|log|history)\s+(?:aksi|perubahan|audit|ai)|(?:apa\s+saja)\s+yang\s+(?:kamu|ai|anda)?\s*(?:ubah|diubah|lakukan|dilakukan|perubahan)|(?:tampilkan|lihat|cek|show)\s+(?:aksi|perubahan|log)\s+(?:ai|yang|nya|sudah)/', $text)) {
            return ['tool' => 'get_action_log', 'arguments' => []];
        }

        // --- Produk terlaris semua cabang (sebelum cabang per periode tunggal) ---
        if ($allBranches && preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku)/', $text)) {
            $arguments = ['period' => match (true) {
                str_contains($text, 'minggu') => 'this_week',
                str_contains($text, 'hari ini') => 'today',
                str_contains($text, 'bulan lalu') => 'last_month',
                default => 'this_month',
            }, 'scope' => 'all'];

            return ['tool' => 'get_top_products', 'arguments' => $arguments];
        }

        // --- Produk terlaris ---
        if (preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku)\s+minggu\s+ini/', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => 'this_week']];
        }
        if (preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku)\s+hari\s+ini/', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => 'today']];
        }
        if (preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku)\s+bulan\s+ini/', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => 'this_month']];
        }
        if (preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku|terlaris\s+ini)/', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => 'this_month']];
        }

        // --- Cek stok (tanpa nama produk tertentu = stok rendah) ---
        if (preg_match('/^(?:cek|lihat|tampilkan|daftar)\s+stok$/i', $text)) {
            return ['tool' => 'check_stock', 'arguments' => ['show_all' => true]];
        }
        if (preg_match('/(?:stok(?:nya)?|persediaan)(?:\s+\w+){0,3}\s+habis/', $text)) {
            return ['tool' => 'check_stock', 'arguments' => ['out_of_stock' => true]];
        }
        if (preg_match('/(?:stok(?:nya)?|persediaan)(?:\s+\w+){0,3}\s+(?:rendah|menipis)/', $text)) {
            return ['tool' => 'check_stock', 'arguments' => []];
        }

        // --- Cek stok produk tertentu ---
        if (preg_match('/(?:cek|lihat|tampilkan)\s+stok\s+(?:produk\s+)?(.+)/i', $text, $m)) {
            return ['tool' => 'check_stock', 'arguments' => ['product_name' => trim($m[1])]];
        }

        // --- Cari produk ---
        if (preg_match('/(?:cari|search|carikan|cariin)\s+(?:produk|barang)\s+(.+)/', $text, $m)) {
            return ['tool' => 'search_product', 'arguments' => ['query' => trim($m[1])]];
        }

        // --- Daftar belanja supplier (kelompok per supplier, harga beli terakhir) ---
        if (preg_match('/(?:daftar|list)\s+(?:belanja(?:an)?|order|pembelian)\s+(?:untuk\s+)?(?:ke\s+)?supplier|(?:belanja|order|pesan)\s+(?:ke\s+)?supplier\s+(?:apa|mana)|(?:siap(?:kan|in)|buat(?:kan)?|susun(?:kan)?)\s+(?:order|daftar\s+belanja)\s+(?:ke\s+)?supplier|(?:total|ringkasan|rekap)\s+(?:belanja|pembelian)\s+(?:per\s+|untuk\s+)?supplier|(?:supplier|vendor)\s+(?:apa|mana)\s+(?:yang|saja\s+yang)\s+perlu\s+(?:diorder|dihubungi|dibeli(?:n)?)/i', $text)) {
            $arguments = [];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'purchase_suggestion', 'arguments' => $arguments];
        }

        // --- Insight laba per produk / produk bocor / bundling / cross-sell ---
        if (preg_match('/(?:produk|barang)\s+(?:bocor|boros)|(?:produk|barang)\s+(?:mana|apa)\s+(?:yang\s+)?(?:bocor|boros|rugi)|(?:insight|analisa|analisis)\s+(?:laba|profit|margin)\s+(?:per\s+produk|produk)|(?:cross[\s-]?sell|bundl(?:e|ing)|(?:s|kombinasi)\s+produk)|(?:sering|biasa|banyak)\s+(?:dibeli|beli|terjual)\s+(?:bareng|bersama|sekaligus|sekalian)|(?:produk|barang)\s+(?:apa|mana)\s+(?:yang\s+)?(?:bisa|cocok|bagus)\s+(?:di\s*[-]?bundle|untuk\s+(?:bundl|promosi)|untuk\s+dipromosi)/i', $text)) {
            $arguments = [];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'product_profit_insight', 'arguments' => $arguments];
        }

        // --- Rekomendasi belanja/restock (saran daftar belanja, bukan langsung buat PO) ---
        if (preg_match('/rekomendasi\s+(?:restock|restok|belanja|pembelian)|(?:saran|anjuran|tips)\s+(?:pembelian|belanja|restock|restok)|(?:daftar|list)\s+(?:belanjaan|belanja|pembelian|restock|restok)|(?:perlu|harus|wajib|enaknya|mending)\s+(?:beli|belanja|restock|restok|pesan)(?:\s+\w+){0,2}\s+apa|beli\s+(?:apa|apa\s+saja)(?:\s+(?:ya|nih|dong))?|(?:siapkan|siapin|buatkan|buat\s+daftar|susun(?:kan)?)\s+(?:daftar\s+)?(?:belanjaan|belanja|restock|restok)|(?:produk|barang)\s+(?:apa|mana)(?:\s+saja)?\s+yang\s+perlu\s+(?:di-?\s*)?(?:restock|restok|beli|dibeli|belanjakan|diisi)/', $text)) {
            $arguments = [];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'recommend_restock', 'arguments' => $arguments];
        }

        // --- Ubah stok massal (mis. "tambah semua stok produk ini jadi 100") ---
        $bulkStockResult = $this->detectBulkStockUpdate($message, $conversation);
        if ($bulkStockResult) {
            return $bulkStockResult;
        }

        // --- Beli / restock barang dari supplier (draft purchase order) ---
        $purchaseResult = $this->detectPurchaseOrder($message);
        if ($purchaseResult) {
            return $purchaseResult;
        }

        // --- Tambah / buat produk ---
        $createResult = $this->detectCreateProduct($message);
        if ($createResult) {
            return $createResult;
        }

        return null;
    }

    /**
     * Deteksi sapaan / basa-basi murni (tanpa permintaan data) agar dibalas
     * langsung tanpa memanggil tool apa pun. Pesan yang memuat kata kunci
     * bisnis atau aksi dianggap bukan basa-basi.
     */
    protected function detectSmallTalk(string $text): ?string
    {
        if (mb_strlen($text) > 60) {
            return null;
        }

        $hasBusinessWord = preg_match('/\b(stok|persediaan|penjualan|omset|omzet|jual|laku|terjual|produk|barang|harga|laba|untung|rugi|margin|transaksi|order|beli|belanja|supplier|pelanggan|customer|laporan|kasir|struk|nota|bayar|hutang|utang|piutang|restock|cabang|outlet|toko|gudang)\b/u', $text) === 1;
        $hasActionWord = preg_match('/\b(tambah(?:kan)?|buat(?:kan)?|ubah|hapus|update|setel|isi|input|cari(?:kan|in)?|tampilkan|lihat|cek|periksa|analisa|analisis|prediksi|hitung|bandingkan|kirim|daftar)\b/u', $text) === 1;

        if ($hasBusinessWord || $hasActionWord) {
            return null;
        }

        if (preg_match('/\b(terima kasih|makasih|makasi|thanks|thx)\b/u', $text)) {
            return 'Sama-sama! Senang bisa membantu. Ada lagi yang bisa Macca bantu?';
        }

        if (preg_match('/\b(hallo|halo|hai|hi|hello|hei|pagi|siang|sore|malam|assalamualaikum|assalam|salam|kabar|test|tes|ping)\b/u', $text)) {
            return 'Halo! Saya Macca, asisten AI toko Anda. Ada yang bisa saya bantu? Coba tanya stok, penjualan, atau produk terlaris.';
        }

        return null;
    }

    /**
     * Deteksi perubahan stok massal yang merujuk hasil cek stok sebelumnya.
     *
     * @return array{tool: string, arguments: array}|array{text: string}|null
     */
    protected function detectBulkStockUpdate(string $message, ?AiConversation $conversation): ?array
    {
        $text = strtolower(trim($message));

        $hasAction = preg_match('/(?:\btambah(?:kan)?\b|\bisi\b|\bpenuhi\b|\bsetel\b|\bubah\b|\bset\b)/', $text);
        $hasBulkTarget = preg_match('/\bsemua\b/', $text) && preg_match('/\bstok\b|\bpersediaan\b/', $text);
        $hasReferencedProducts = preg_match('/(?:stok|persediaan)\s+(?:produk|barang)\s+(?:ini|tersebut|tsb)/', $text);

        if (! $hasAction || (! $hasBulkTarget && ! $hasReferencedProducts)) {
            return null;
        }

        if (preg_match('/(?:jadi|menjadi|=)\s*([\d.]+(?:,\d+)?)/', $text, $m)) {
            $arguments = ['set_to' => (int) str_replace(['.', ','], '', $m[1])];
        } elseif (preg_match('/([\d.]+(?:,\d+)?)/', $text, $m)) {
            $arguments = ['add' => (int) str_replace(['.', ','], '', $m[1])];
        } else {
            return ['text' => 'Berapa jumlah stoknya? Contoh: "tambah semua stok produk ini jadi 100".'];
        }

        $products = $this->resolvePreviousStockProducts($conversation);

        if ($products->isEmpty()) {
            return ['text' => 'Saya belum tahu produk yang dimaksud. Cek dulu daftarnya (misalnya "cek stok yang habis"), lalu ulangi perintahnya.'];
        }

        $arguments['product_ids'] = $products->pluck('id')->all();
        $arguments['product_names'] = $products->pluck('name')->all();
        $arguments['reason'] = 'Penyesuaian massal via AI Assistant';

        return ['tool' => 'update_stock_bulk', 'arguments' => $arguments];
    }

    /**
     * Ambil daftar produk dari hasil tool check_stock terakhir di percakapan ini.
     * Hanya hasil dari ~10 pesan terakhir yang dipakai agar konteks lama tidak
     * terbawa ke perintah baru (mis. "tambah semua stok" setelah topik berganti).
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string}>
     */
    protected function resolvePreviousStockProducts(?AiConversation $conversation): \Illuminate\Support\Collection
    {
        if (! $conversation) {
            return collect();
        }

        $recentIds = $conversation->messages()
            ->reorder()
            ->latest('id')
            ->limit(10)
            ->pluck('id');

        $resultMessage = $conversation->messages()
            ->whereIn('id', $recentIds)
            ->where('role', 'user')
            ->where('content', 'like', 'Hasil dari tool check_stock:%')
            ->reorder()
            ->latest('id')
            ->first();

        if (! $resultMessage) {
            return collect();
        }

        $decoded = json_decode(substr($resultMessage->content, strpos($resultMessage->content, '{') ?: 0), true);

        return collect($decoded['data']['products'] ?? [])
            ->filter(fn ($product) => isset($product['id'], $product['name']))
            ->map(fn ($product) => ['id' => (int) $product['id'], 'name' => $product['name']])
            ->values();
    }

    /**
     * Balas user langsung tanpa memanggil provider AI.
     *
     * @return array<string, mixed>
     */
    protected function respondDirectly(AiConversation $conversation, string $text): array
    {
        $finalMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $text,
        ]);

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $finalMessage->id,
                'role' => 'assistant',
                'content' => $text,
                'timestamp' => $finalMessage->created_at->format('H:i'),
            ],
        ];
    }

    /**
     * Deteksi permintaan beli/pesan/restock barang (mis. dari supplier)
     * menjadi draft purchase order yang menunggu konfirmasi user.
     *
     * @return array{tool: string, arguments: array}|null
     */
    protected function detectPurchaseOrder(string $message): ?array
    {
        $verbPattern = '(?:beli(?:in|kan|an)?|pesan(?:in|an)?|restock|restok)';

        if (! preg_match('/\b'.$verbPattern.'\b/iu', $message)) {
            return null;
        }

        // Butuh konteks pembelian: supplier, bayar, atau jumlah + satuan.
        $hasContext = preg_match(
            '/\b(?:supplier|bayar|tempo|kredit|credit|cash|tunai|utang|restock|restok|pembelian)\b|\bdari\b|\b\d+\s*(?:pcs|bks|dus|box|pack|botol|sachet|kg|gram|liter|ltr|ml|rim|lusin|karung|jerigen|galon|roll)\b/iu',
            $message
        );
        if (! $hasContext) {
            return null;
        }

        // Potong nama produk + jumlah: dari kata kerja sampai "dari supplier ... , bayar ..."
        if (! preg_match('/'.$verbPattern.'\s+(?:stok\s+|barang\s+|ulang\s+)?(.+?)(?:\s+dari\s+(?:supplier\s+)?|\s+bayar\s+|\s*,\s*|\s+ke\s+supplier\s+|$)/iu', $message, $m)) {
            return null;
        }

        $chunk = trim($m[1], " \t\n\r\0\x0B,.;");

        // Pisahkan nama produk dan jumlah: jumlah = angka terakhir (+ satuan opsional)
        if ($chunk === '' || ! preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)\s*([a-z]+)?$/iu', $chunk, $im)) {
            return null;
        }

        $name = trim($im[1]);
        if ($name === '') {
            return null;
        }

        $item = [
            'product_name' => $name,
            'quantity' => (int) round((float) str_replace(',', '.', $im[2])),
        ];

        if (! empty($im[3])) {
            $item['unit'] = strtolower($im[3]);
        }

        $arguments = ['items' => [$item]];

        // Nama supplier setelah "dari [supplier]"
        if (preg_match('/dari\s+(?:supplier\s+)?(.+?)(?:,|\.|\s+bayar\s+|$)/iu', $message, $sm)) {
            $supplier = trim($sm[1], " \t\n\r\0\x0B,.");
            if ($supplier !== '') {
                $arguments['supplier_name'] = $supplier;
            }
        }

        // Metode bayar: cash/tunai = cash, kredit/tempo/utang = credit
        if (preg_match('/\b(cash|tunai|kredit|credit|utang|tempo)\b/iu', $message, $pm)) {
            $arguments['payment_method'] = in_array(strtolower($pm[1]), ['kredit', 'credit', 'utang', 'tempo'], true)
                ? 'credit'
                : 'cash';
        }

        return ['tool' => 'create_purchase_order', 'arguments' => $arguments];
    }

    protected function detectCreateProduct(string $message): ?array
    {
        // Pesan beli/restock dari supplier bukan tambah produk baru.
        if (preg_match('/\b(?:supplier|restock|restok|pembelian)\b|(?:beli(?:in|kan)?|pesan(?:in)?)\s+(?:stok|barang|ke\b)|\bbayar\s+(?:cash|tunai|kredit|credit|utang|tempo)\b/iu', $message)) {
            return null;
        }

        $hasCreateIntent = preg_match('/(?:tambah(?:kan)?|buat|input|masukkan|insert|create)\s+(?:produk|barang)/i', $message);
        $hasProductDetails = preg_match('/\bharga\b/i', $message) && preg_match('/\b(?:modal|hpp|stok|satuan)\b/i', $message);

        if (! $hasCreateIntent && ! $hasProductDetails) {
            return null;
        }

        $args = [];

        if (preg_match('/(?:tambah(?:kan)?|buat|input|masukkan|insert|create)\s+(?:produk|barang)\s+(.+?)(?:,|\s+(?:harga|modal|kategori|stok|satuan|hpp|cost|sku)\b|$)/i', $message, $m) || preg_match('/^\s*(.+?)\s+harga\b/i', $message, $m)) {
            $name = trim($m[1], " \t\n\r\0\x0B,.");
            if ($name !== '') {
                $args['name'] = $name;
            }
        }

        if (empty($args['name'])) {
            return null;
        }

        // Ekstrak harga jual
        if (preg_match('/(?:harga\s*(?:jual)?|price)\s*[:\s]*(?:rp\.?\s*)?([\d.,]+)/i', $message, $m)) {
            $args['price'] = $this->parseIndonesianNumber($m[1]);
        }

        // Ekstrak harga modal / HPP
        if (preg_match('/(?:modal|hpp|harga\s*(?:beli|pokok)|cost)\s*[:\s]*(?:rp\.?\s*)?([\d.,]+)/i', $message, $m)) {
            $args['cost'] = $this->parseIndonesianNumber($m[1]);
        }

        // Ekstrak kategori
        if (preg_match('/kategori\s*[:\s]*([^\d,]+?)(?:\s*,|\s+(?:harga|modal|stok|satuan|hpp)\b|$)/i', $message, $m)) {
            $cat = trim($m[1], " \t\n\r\0\x0B,.");
            if ($cat !== '') {
                $args['category'] = $cat;
            }
        }

        // Ekstrak stok
        if (preg_match('/stok\s*(?:awal)?\s*[:\s]*(\d+)/i', $message, $m)) {
            $args['stock'] = (int) $m[1];
        }

        // Ekstrak satuan
        if (preg_match('/satuan\s*[:\s]*(\w+)/i', $message, $m)) {
            $args['unit'] = strtolower($m[1]);
        }

        // Ekstrak SKU
        if (preg_match('/sku\s*[:\s]*([A-Za-z0-9-]+)/i', $message, $m)) {
            $args['sku'] = $m[1];
        }

        // Harga wajib ada
        if (empty($args['price'])) {
            return null;
        }

        return ['tool' => 'create_product', 'arguments' => $args];
    }

    /**
     * Parse angka format Indonesia (titik = ribuan, koma = desimal).
     */
    protected function parseIndonesianNumber(string $value): float
    {
        $cleaned = str_replace(['.', ' '], '', $value);
        $cleaned = str_replace(',', '.', $cleaned);

        return (float) $cleaned;
    }

    protected function parsePromptToolCall(?string $content): ?array
    {
        if (! $content) {
            return null;
        }

        // Cari semua posisi '{' lalu coba ekstrak JSON object yang valid
        $offset = 0;
        while (($pos = strpos($content, '{', $offset)) !== false) {
            $json = $this->extractJsonObject($content, $pos);
            if ($json) {
                $parsed = json_decode($json, true);
                if ($parsed && isset($parsed['tool'])) {
                    return [
                        'tool' => $parsed['tool'],
                        'arguments' => $parsed['arguments'] ?? [],
                    ];
                }
            }
            $offset = $pos + 1;
        }

        return null;
    }

    protected function extractJsonObject(string $text, int $start): ?string
    {
        if ($text[$start] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < strlen($text); $i++) {
            $ch = $text[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($ch === '\\' && $inString) {
                $escaped = true;

                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    protected function handlePromptToolCall(AiConversation $conversation, array $toolCall): array
    {
        if ($this->requiresConfirmation($toolCall['tool'])) {
            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'confirmation' => [
                    'tool' => $toolCall['tool'],
                    'arguments' => $this->enrichConfirmationArguments($toolCall['tool'], $toolCall['arguments']),
                ],
            ];
        }

        return $this->executePromptToolCall($conversation, $toolCall);
    }

    /**
     * Tool yang mengubah data wajib dikonfirmasi user sebelum dieksekusi.
     */
    protected function requiresConfirmation(string $toolName): bool
    {
        if (! config('ai.confirmation_required', true)) {
            return false;
        }

        return in_array($toolName, (array) config('ai.confirmation_tools', []), true);
    }

    /**
     * Cari tool call pertama dari respons model yang butuh konfirmasi.
     *
     * @return array{tool: string, arguments: array}|null
     */
    protected function findConfirmationForToolCalls(array $toolCalls): ?array
    {
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall['function']['name'] ?? null;

            if (! is_string($toolName) || ! $this->requiresConfirmation($toolName)) {
                continue;
            }

            $arguments = json_decode($toolCall['function']['arguments'] ?? '[]', true) ?? [];

            return [
                'tool' => $toolName,
                'arguments' => $this->enrichConfirmationArguments($toolName, is_array($arguments) ? $arguments : []),
            ];
        }

        return null;
    }

    /**
     * Tambahkan data tampilan (mis. nama produk) agar kartu konfirmasi informatif.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function enrichConfirmationArguments(string $toolName, array $arguments): array
    {
        if ($toolName === 'update_stock' && ! empty($arguments['product_id']) && $this->store) {
            $product = Product::where('store_id', $this->store->id)->find($arguments['product_id']);

            if ($product) {
                $arguments['product_name'] = $product->name;
                $arguments['unit'] = $product->unit ?? 'pcs';
            }
        }

        return $arguments;
    }

    public function executeConfirmedToolCall(AiConversation $conversation, array $toolCall): array
    {
        return $this->executePromptToolCall($conversation, $toolCall);
    }

    /**
     * Catat penolakan konfirmasi aksi ke audit log agar muncul di riwayat aksi AI.
     *
     * @param  array{tool?: string, arguments?: array}  $confirmation
     */
    public function logConfirmationRejected(array $confirmation): void
    {
        if (! config('ai.audit_log_enabled')) {
            return;
        }

        AiAuditLog::create([
            'user_id' => $this->user?->id,
            'store_id' => $this->store?->id,
            'action' => 'confirmation_rejected',
            'tool_name' => $confirmation['tool'] ?? null,
            'parameters' => $confirmation['arguments'] ?? [],
            'result' => ['message' => 'User menolak konfirmasi aksi'],
            'status' => 'cancelled',
        ]);
    }

    protected function executePromptToolCall(AiConversation $conversation, array $toolCall): array
    {
        $toolName = $toolCall['tool'];
        $toolArgs = $toolCall['arguments'];

        // Eksekusi tool
        $result = $this->toolRegistry->execute($toolName, $toolArgs, $this->user);

        // Simpan hasil tool
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => "Hasil dari tool {$toolName}: ".$result->toJson(),
        ]);

        // Audit log
        $this->logAudit($toolName, $toolArgs, $result);

        // Fast path: jika tool gagal, langsung balas tanpa panggil API lagi
        if (! $result->isSuccess()) {
            $finalMessage = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => "Maaf, terjadi kesalahan saat menjalankan **{$toolName}**: {$result->error}. Silakan coba lagi atau ubah pertanyaan Anda.",
            ]);

            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'message' => [
                    'id' => $finalMessage->id,
                    'role' => 'assistant',
                    'content' => $finalMessage->content,
                    'timestamp' => $finalMessage->created_at->format('H:i'),
                ],
                'tool_results' => [
                    [
                        'tool_call_id' => null,
                        'name' => $toolName,
                        'result' => $result->toArray(),
                    ],
                ],
            ];
        }

        $finalMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $this->formatDirectToolResult($toolName, $result->data, $result->message),
        ]);

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $finalMessage->id,
                'role' => 'assistant',
                'content' => $finalMessage->content,
                'timestamp' => $finalMessage->created_at->format('H:i'),
            ],
            'tool_results' => [
                [
                    'tool_call_id' => null,
                    'name' => $toolName,
                    'result' => $result->toArray(),
                ],
            ],
        ];
    }

    protected function formatDirectToolResult(string $toolName, array $data, string $message): string
    {
        if ($toolName === 'get_top_products' && ! empty($data['products'])) {
            $products = collect($data['products'])->map(fn (array $product, int $index) => sprintf(
                '%d. **%s** — %s terjual (%s)',
                $index + 1,
                $product['product_name'],
                $product['quantity_sold'],
                $product['revenue_formatted'],
            ))->implode("\n");

            return "{$message}\n\n{$products}";
        }

        if ($toolName === 'check_stock' && ! empty($data['products'])) {
            $products = collect($data['products'])->map(fn (array $product) => sprintf(
                '- **%s** — %s %s',
                $product['name'],
                $product['stock'],
                $product['unit'],
            ))->implode("\n");

            return "{$message}\n\n{$products}";
        }

        return $message;
    }

    protected function handleToolCalls(AiConversation $conversation, AiMessage $assistantMessage, AiResponse $response, ?callable $onDelta = null): array
    {
        $toolResults = [];

        foreach ($response->getToolCalls() as $toolCall) {
            $toolName = $toolCall['function']['name'];
            $toolArgs = json_decode($toolCall['function']['arguments'], true) ?? [];
            $toolCallId = $toolCall['id'];

            // Eksekusi tool
            $result = $this->toolRegistry->execute($toolName, $toolArgs, $this->user);

            // Simpan hasil tool
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'tool',
                'content' => $result->toJson(),
                'tool_call_id' => $toolCallId,
            ]);

            // Audit log
            $this->logAudit($toolName, $toolArgs, $result);

            $toolResults[] = [
                'tool_call_id' => $toolCallId,
                'name' => $toolName,
                'result' => $result->toArray(),
            ];
        }

        // Kirim hasil tool ke AI untuk respons final
        $messages = $this->buildMessages($conversation);
        $finalResponse = $onDelta
            ? $this->provider->chatStream($messages, [], $onDelta)
            : $this->provider->chat($messages);

        if ($finalResponse->isSuccess()) {
            $finalMessage = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $finalResponse->getContent(),
                'tokens_used' => $finalResponse->tokensUsed,
            ]);

            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'message' => [
                    'id' => $finalMessage->id,
                    'role' => 'assistant',
                    'content' => $finalResponse->getContent(),
                    'timestamp' => $finalMessage->created_at->format('H:i'),
                ],
                'tool_results' => $toolResults,
            ];
        }

        return [
            'success' => false,
            'error' => $finalResponse->error,
            'conversation_id' => $conversation->id,
            'tool_results' => $toolResults,
            'status' => 502,
        ];
    }

    protected function buildMessages(AiConversation $conversation): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->promptBuilder->buildSystemPrompt()],
        ];

        // Ambil 20 pesan terakhir untuk konteks
        $recentMessages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->reverse();

        foreach ($recentMessages as $msg) {
            $message = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];

            if ($msg->role === 'assistant' && $msg->tool_calls) {
                $message['tool_calls'] = $msg->tool_calls;
            }

            if ($msg->role === 'tool') {
                $message['tool_call_id'] = $msg->tool_call_id;
            }

            $messages[] = $message;
        }

        return $messages;
    }

    protected function logAudit(string $toolName, array $args, ToolResult $result): void
    {
        if (! config('ai.audit_log_enabled')) {
            return;
        }

        AiAuditLog::create([
            'user_id' => $this->user?->id,
            'store_id' => $this->store?->id,
            'action' => 'tool_execution',
            'tool_name' => $toolName,
            'parameters' => $args,
            'result' => $result->toArray(),
            'status' => $result->isSuccess() ? 'success' : 'failed',
        ]);
    }

    public function validateConnection(): bool
    {
        return $this->provider->validateConnection();
    }

    public function getAvailableModels(): array
    {
        return $this->provider->getModels();
    }

    public function getToolRegistry(): AiToolRegistry
    {
        return $this->toolRegistry;
    }
}
