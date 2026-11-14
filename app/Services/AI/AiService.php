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

    /** Jumlah pesan terakhir yang dikirim sebagai konteks. */
    protected const HISTORY_LIMIT = 24;

    /** Batas putaran pemanggilan tool dalam satu jawaban. */
    protected const MAX_TOOL_ROUNDS = 4;

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
                // Bawaan kolom ini 0.7 — terlalu longgar untuk jawaban berbasis angka.
                'temperature' => min((float) $config->temperature, (float) config('ai.chat_temperature_max', 0.3)),
                'supports_function_calling' => $config->supports_function_calling,
            ]);
        }

        // Fallback: pengaturan AI dari halaman Super Admin (tabel ai_settings)
        AiSetting::applyToRuntime();

        // Fallback terakhir ke config file (.env)
        $providerName = config('ai.default_provider', 'openai');
        $providerConfig = config("ai.providers.{$providerName}", config('ai.providers.openai'));

        // Asisten ini menjawab dari angka toko: pakai temperature rendah agar
        // model tidak "berkreasi". Bawaan provider (0.7) terlalu longgar.
        $providerConfig['temperature'] = (float) config('ai.chat_temperature', 0.2);

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
        $plainContent = $this->cleanAssistantContent($response->getContent());

        $assistantMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $plainContent,
            'tool_calls' => $response->getToolCalls(),
            'tokens_used' => $response->tokensUsed,
        ]);

        return [
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $plainContent,
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

        // --- Komisi barber/teknisi (khusus barbershop) ---
        if (preg_match('/\b(komisi|commission)\b.*\b(barber|teknisi|cukur|pangkas)\b|\b(barber|teknisi|cukur|pangkas)\b.*\b(komisi|commission)\b|\bcek\b.*\bkomisi\b|\bkomisi\s+barber\b|\bkomisi\s+teknisi\b/u', $text)) {
            $arguments = ['period' => $this->periodFromText($text, 'this_month')];

            if (preg_match('/\b(?:barber|teknisi|cukur|pangkas)\s+([a-z0-9][a-z0-9\s.\'\-]{1,30})/iu', $message, $m)) {
                $candidate = trim($m[1]);
                $candidate = trim((string) preg_replace('/\s+(hari\s+ini|kemarin|minggu\s+ini|minggu\s+lalu|bulan\s+ini|bulan\s+lalu|tahun\s+ini|hari|minggu|bulan|tahun)\s*$/iu', '', $candidate));
                $candidate = trim((string) preg_replace('/\s+(dong|ya|yuk|deh|nih|aja|kak|bang|yaa|saya|kita|aku|enggak|tidak)\s*$/iu', '', $candidate));

                // Lewati kandidat yang bukan nama: sisa frasa periode, kata ganti, atau konteks cabang.
                $isNotName = $candidate === ''
                    || preg_match('/^(hari|minggu|bulan|tahun|kemarin|saya|kita|aku|semua|seluruh|ini|lalu|dong|ya)\b.*$/iu', $candidate) === 1
                    || preg_match('/\b(cabang|outlet|toko)\b/u', $candidate) === 1;

                if (! $isNotName) {
                    $arguments['barber_name'] = $candidate;
                }
            }

            if (preg_match('/(?:semua|seluruh)\s*(?:cabang|outlet|toko)|(?:antarcabang|antar\s*cabang)|lintas\s*(?:cabang|outlet)/', $text) === 1) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'get_barber_commissions', 'arguments' => $arguments];
        }

        // Frasa yang meminta agregasi lintas cabang (multi-outlet)
        $allBranches = preg_match('/(?:semua|seluruh)\s*(?:cabang|outlet|toko)|(?:antarcabang|antar\s*cabang)|lintas\s*(?:cabang|outlet)/', $text) === 1;

        // --- Laporan laba rugi (pendapatan - HPP - beban operasional = laba bersih) ---
        if (preg_match('/laporan\s+(?:laba|rugi|keuangan|profit)|laba\s+(?:bersih|rugi)|untung\s+(?:bersih|kotor)|rugi\s+bersih|beban\s+operasional|biaya\s+operasional|berapa\s+(?:laba|untung|rugi)|(?:cek|lihat|tampilkan|hitung)\s+(?:laba|untung|rugi)(?!\s+kotor)|(?:laba|untung|rugi)\s+(?:saya|kita|usaha|bisnis|toko)?\s*(?:hari\s+ini|kemarin|minggu|bulan|tahun)/', $text)) {
            $arguments = ['period' => $this->periodFromText($text, 'this_month')];

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
            $arguments = ['period' => $this->periodFromText($text, 'this_month')];

            return ['tool' => 'compare_branches', 'arguments' => $arguments];
        }

        // --- Deteksi anomali transaksi / anti-fraud kasir ---
        if (preg_match('/transaksi\s+(?:yang\s+)?(?:mencurigakan|aneh|janggal|ganjil)|\b(?:anomali|fraud|kecurangan)\b|anti[\s-]*fraud|void\s+(?:berulang|banyak|terus|beruntun)|(?:diskon)\s+(?:aneh|tidak\s+wajar|besar|gede|berlebihan|gila|anehnya)|selisih\s+kas|audit\s+(?:kasir|transaksi|penjualan)|kasir\s+(?:nakal|curang)|tampilkan\s+(?:semua\s+)?data(?:nya)?|data\s+selisih\s+kas|data\s+diskon|data\s+indikasi/', $text)) {
            $arguments = ['period' => $this->periodFromText($text, 'this_month')];

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
            $arguments = ['period' => $this->periodFromText($text, 'this_week')];

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
            $arguments = ['period' => $this->periodFromText($text, 'this_month'), 'scope' => 'all'];

            return ['tool' => 'get_sales_summary', 'arguments' => $arguments];
        }

        // --- Daftar produk yang terjual pada suatu periode ---
        if (preg_match('/(?:daftar|apa\s+saja|rincian|detail|tampilkan)\s+(?:semua\s+)?(?:produk|barang|item)\b.*(?:terjual|penjualan|laku)|(?:produk|barang|item)\s+(?:apa\s+saja\s+)?yang\s+(?:terjual|laku)/u', $text)) {
            $arguments = ['period' => $this->periodFromText($text, 'today'), 'limit' => 20];

            if ($allBranches) {
                $arguments['scope'] = 'all';
            }

            return ['tool' => 'get_top_products', 'arguments' => $arguments];
        }

        // --- Ringkasan penjualan untuk periode yang disebut (hari ini, kemarin, minggu lalu, dst.) ---
        if (preg_match('/\b(?:penjualan|omset|omzet)\b/u', $text) && AiPeriod::detect($text) !== null
            && ! preg_match('/\b(?:tambah|buat|ubah|hapus|input)\b/u', $text)) {
            return ['tool' => 'get_sales_summary', 'arguments' => ['period' => AiPeriod::detect($text)]];
        }

        // --- Riwayat aksi AI ---
        if (preg_match('/(?:riwayat|log|history)\s+(?:aksi|perubahan|audit|ai)|(?:apa\s+saja)\s+yang\s+(?:kamu|ai|anda)?\s*(?:ubah|diubah|lakukan|dilakukan|perubahan)|(?:tampilkan|lihat|cek|show)\s+(?:aksi|perubahan|log)\s+(?:ai|yang|nya|sudah)/', $text)) {
            return ['tool' => 'get_action_log', 'arguments' => []];
        }

        // --- Produk terlaris semua cabang (sebelum cabang per periode tunggal) ---
        if ($allBranches && preg_match('/(?:produk|barang)\s+(?:terlaris|paling\s+laku)/', $text)) {
            $arguments = ['period' => $this->periodFromText($text, 'this_month'), 'scope' => 'all'];

            return ['tool' => 'get_top_products', 'arguments' => $arguments];
        }

        // --- Produk terlaris / paling tidak laku ---
        if (preg_match('/(?:produk|barang)\s+(?:yang\s+)?(?:terlaris|paling\s+(?:laku|laris|banyak\s+terjual))/u', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => $this->periodFromText($text, 'this_month')]];
        }
        if (preg_match('/(?:produk|barang)\s+(?:yang\s+)?(?:paling\s+(?:tidak|nggak|gak|ga|kurang)\s+laku|paling\s+sedikit\s+terjual|kurang\s+laku)/u', $text)) {
            return ['tool' => 'get_top_products', 'arguments' => ['period' => $this->periodFromText($text, 'this_month'), 'order' => 'bottom']];
        }

        // --- Cek stok (tanpa nama produk tertentu = stok rendah) ---
        $stockCategory = preg_match('/\b(?:stok|persediaan)/u', $text) ? $this->detectCategoryInText($text) : null;
        $withCategory = fn (array $arguments) => $stockCategory !== null ? $arguments + ['category' => $stockCategory] : $arguments;

        if (preg_match('/^(?:cek|lihat|tampilkan|daftar)\s+(?:semua\s+)?stok$/i', $text)) {
            return ['tool' => 'check_stock', 'arguments' => ['show_all' => true]];
        }
        if (preg_match('/(?:stok(?:nya)?|persediaan)(?:\s+\w+){0,3}\s+(?:habis|kosong)/u', $text)) {
            return ['tool' => 'check_stock', 'arguments' => $withCategory(['out_of_stock' => true])];
        }
        if (preg_match('/(?:stok(?:nya)?|persediaan)(?:\s+\w+){0,3}\s+(?:rendah|menipis|sedikit|tipis|kritis)/u', $text)) {
            return ['tool' => 'check_stock', 'arguments' => $withCategory([])];
        }
        if ($stockCategory !== null && preg_match('/\b(?:cek|lihat|tampilkan|daftar)\b/u', $text)) {
            return ['tool' => 'check_stock', 'arguments' => ['category' => $stockCategory, 'show_all' => true]];
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
        if (preg_match('/(?:produk|barang)\s+(?:bocor|boros)|(?:produk|barang)\s+(?:mana|apa)\s+(?:yang\s+)?(?:bocor|boros|rugi)|(?:insight|analisa|analisis)\s+(?:laba|profit|margin)\s+(?:per\s+produk|produk)|(?:\bcross[\s-]?sell\b|\bbundl(?:e|ing)\b|\b(?:paket|kombinasi)\s+produk\b)|(?:sering|biasa|banyak)\s+(?:dibeli|beli|terjual)\s+(?:bareng|bersama|sekaligus|sekalian)|(?:produk|barang)\s+(?:apa|mana)\s+(?:yang\s+)?(?:bisa|cocok|bagus)\s+(?:di\s*[-]?bundle|untuk\s+(?:bundl|promosi)|untuk\s+dipromosi)/i', $text)) {
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

        // --- Tanggal spesifik ("tanggal 24", "24 September", "24/09", dll.)
        // dicek terakhir agar tidak menangkap pesan yang punya intent lain. ---
        $specificDate = $this->detectSpecificDate($text, $message, $conversation);
        if ($specificDate !== null) {
            return $specificDate;
        }

        return null;
    }

    /** Periode yang disebut user; bila tidak disebut, pakai bawaan tool. */
    protected function periodFromText(string $text, string $default): string
    {
        return AiPeriod::detect($text) ?? $default;
    }

    /**
     * Kategori produk toko ini yang disebut dalam pesan (mis. "bahan baku"),
     * dicocokkan dengan nama kategori yang benar-benar ada — bukan tebakan.
     */
    protected function detectCategoryInText(string $text): ?string
    {
        if (! $this->store) {
            return null;
        }

        if (preg_match('/\bkategori\s+([\p{L}\d][\p{L}\d\s&\-]{1,40}?)(?:\s+(?:yang|dengan|stok(?:nya)?|habis|rendah|menipis)\b|[.,?!]|$)/u', $text, $m)) {
            return trim($m[1]);
        }

        $names = \App\Models\ProductCategory::query()
            ->where('store_id', $this->store->id)
            ->pluck('name')
            ->merge(Product::query()->where('store_id', $this->store->id)->whereNotNull('category')->distinct()->pluck('category'))
            ->filter(fn ($name) => is_string($name) && mb_strlen(trim($name)) >= 4)
            ->map(fn (string $name) => trim($name))
            ->unique()
            ->sortByDesc(fn (string $name) => mb_strlen($name));

        $compact = preg_replace('/\s+/u', '', $text);

        foreach ($names as $name) {
            $needle = mb_strtolower($name);

            if (str_contains($text, $needle) || str_contains((string) $compact, (string) preg_replace('/\s+/u', '', $needle))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Deteksi pesan yang menyebutkan tanggal/rentang tanggal spesifik,
     * lalu sesuaikan dengan konteks percakapan sebelumnya.
     *
     * Contoh pola yang dikenali:
     *   "tanggal 24"          → penjualan tgl 24 bulan ini (atau bulan lalu jika > hari ini)
     *   "24 September"        → penjualan 24 September tahun ini
     *   "24/09"               → penjualan 24 September tahun ini
     *   "24-09-2026"          → penjualan 24 September 2026
     *   "1 sampai 10 September" → penjualan rentang tsb
     *
     * Jika percakapan sebelumnya tentang penjualan → get_sales_summary
     * Jika tentang produk terlaris → get_top_products
     * Jika tidak ada konteks sebelumnya → get_sales_summary (default)
     *
     * @return array{tool: string, arguments: array}|null
     */
    protected function detectSpecificDate(string $text, string $original, ?AiConversation $conversation): ?array
    {
        $bulanMap = [
            'januari' => 1, 'jan' => 1,
            'februari' => 2, 'feb' => 2,
            'maret' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5,
            'juni' => 6, 'jun' => 6,
            'juli' => 7, 'jul' => 7,
            'agustus' => 8, 'agu' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9,
            'oktober' => 10, 'okt' => 10, 'oct' => 10,
            'november' => 11, 'nov' => 11,
            'desember' => 12, 'des' => 12, 'dec' => 12,
        ];

        $now = \Illuminate\Support\Carbon::now();
        $dateFrom = null;
        $dateTo = null;

        // --- Format: DD-MM-YYYY atau DD/MM/YYYY ---
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $text, $m)) {
            $dateFrom = \Illuminate\Support\Carbon::createSafe((int) $m[3], (int) $m[2], (int) $m[1])?->startOfDay();
            $dateTo = $dateFrom?->copy()->endOfDay();
        }

        // --- Format: DD/MM (tanpa tahun) ---
        elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})\b/', $text, $m)) {
            $year = $now->year;
            $dateFrom = \Illuminate\Support\Carbon::createSafe($year, (int) $m[2], (int) $m[1])?->startOfDay();
            $dateTo = $dateFrom?->copy()->endOfDay();
        }

        // --- Format: DD <bulan> [YYYY] (mis. "24 September" atau "24 September 2026") ---
        elseif (preg_match('/\b(\d{1,2})\s+('.implode('|', array_keys($bulanMap)).')(?:\s+(\d{4}))?\b/u', $text, $m)) {
            $day = (int) $m[1];
            $month = $bulanMap[$m[2]] ?? null;
            $year = isset($m[3]) && $m[3] ? (int) $m[3] : $now->year;

            if ($month) {
                $dateFrom = \Illuminate\Support\Carbon::createSafe($year, $month, $day)?->startOfDay();
                $dateTo = $dateFrom?->copy()->endOfDay();
            }
        }

        // --- Format: "tanggal N" saja (mis. "tanggal 24") ---
        elseif (preg_match('/\btanggal\s+(\d{1,2})\b/u', $text, $m)) {
            $day = (int) $m[1];
            $month = $now->month;
            $year = $now->year;

            // Jika tanggal sudah lewat bulan ini, gunakan bulan ini; jika belum ada di bulan ini, anggap bulan lalu
            $candidate = \Illuminate\Support\Carbon::createSafe($year, $month, $day);

            if (! $candidate) {
                return null;
            }

            if ($candidate->gt($now)) {
                $candidate->subMonth();
            }

            $dateFrom = $candidate->startOfDay();
            $dateTo = $candidate->copy()->endOfDay();
        }

        // --- Format: rentang "1 sampai 10 September" / "1-10 September" ---
        elseif (preg_match('/\b(\d{1,2})\s+(?:sampai|hingga|s\/d|sd|-)\s+(\d{1,2})\s+('.implode('|', array_keys($bulanMap)).')(?:\s+(\d{4}))?\b/u', $text, $m)) {
            $month = $bulanMap[$m[3]] ?? null;
            $year = isset($m[4]) && $m[4] ? (int) $m[4] : $now->year;

            if ($month) {
                $dateFrom = \Illuminate\Support\Carbon::createSafe($year, $month, (int) $m[1])?->startOfDay();
                $dateTo = \Illuminate\Support\Carbon::createSafe($year, $month, (int) $m[2])?->endOfDay();
            }
        }

        if (! $dateFrom || ! $dateTo) {
            return null;
        }

        // Tentukan tool berdasarkan konteks percakapan sebelumnya
        $tool = 'get_sales_summary';
        $args = [
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
        ];

        if ($conversation) {
            $lastTool = $this->resolveLastToolFromConversation($conversation);

            if ($lastTool === 'get_top_products') {
                $tool = 'get_top_products';
                $args = [
                    'date_from' => $dateFrom->format('Y-m-d'),
                    'date_to' => $dateTo->format('Y-m-d'),
                ];
            }
        }

        return ['tool' => $tool, 'arguments' => $args];
    }

    /**
     * Ambil nama tool terakhir yang dieksekusi dalam percakapan ini
     * (dari ~5 pesan terakhir) untuk menentukan konteks follow-up.
     */
    protected function resolveLastToolFromConversation(?AiConversation $conversation): ?string
    {
        if (! $conversation) {
            return null;
        }

        $lastToolMsg = $conversation->messages()
            ->where('role', 'user')
            ->where('content', 'like', 'Hasil dari tool %')
            ->reorder()
            ->latest('id')
            ->first();

        if (! $lastToolMsg) {
            return null;
        }

        if (preg_match('/^Hasil dari tool (\w+):/', $lastToolMsg->content, $m)) {
            return $m[1];
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

        $hasBusinessWord = preg_match('/\b(stok|persediaan|penjualan|omset|omzet|jual|laku|terjual|produk|barang|harga|laba|untung|rugi|margin|transaksi|order|beli|belanja|supplier|pelanggan|customer|laporan|kasir|struk|nota|bayar|hutang|utang|piutang|restock|cabang|outlet|toko|gudang|komisi|commission|barber|teknisi|cukur|pangkas)\b/u', $text) === 1;
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

        $hasAction = preg_match('/(?:\btambah(?:kan)?\b|\bisi\b|\bpenuhi\b|\bsetel\b|\bubah\b|\bset\b|\bupdate\b)/', $text);
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

        $hasCreateIntent = preg_match('/(?:\btambah(?:kan)?\b|\bmenambah(?:kan)?\b|\bnambah(?:kan)?\b|\bbuat(?:kan)?\b|\binput\b|\bmasukkan\b|\binsert\b|\bcreate\b)\s+(?:\w+\s+){0,2}(?:produk|barang)/iu', $message) === 1;
        $hasProductDetails = preg_match('/\bharga\b/i', $message) && preg_match('/\b(?:modal|hpp|stok|satuan)\b/i', $message);

        if (! $hasCreateIntent && ! $hasProductDetails) {
            return null;
        }

        $args = [];

        if (preg_match('/(?:\btambah(?:kan)?\b|\bmenambah(?:kan)?\b|\bnambah(?:kan)?\b|\bbuat(?:kan)?\b|\binput\b|\bmasukkan\b|\binsert\b|\bcreate\b)\s+(?:\w+\s+){0,2}(?:produk|barang)\s+(.+?)(?:,|\s+(?:harga|modal|kategori|stok|satuan|hpp|cost|sku)\b|$)/iu', $message, $m) || preg_match('/^\s*(.+?)\s+harga\b/i', $message, $m)) {
            $name = trim($m[1], " \t\n\r\0\x0B,.");
            $name = trim((string) preg_replace('/^(?:baru|yang|ini|itu|saya|kita)\s+/iu', '', $name));
            if ($name !== '' && ! preg_match('/^(?:baru|yang|ini|itu|saja|saya|kita|donk|dong|ya|aja|dulu|dong ya)$/iu', $name)) {
                $args['name'] = $name;
            }
        }

        if (empty($args['name'])) {
            if ($hasCreateIntent) {
                return ['text' => 'Siap, saya bantu tambahkan produk baru. Sebutkan **nama produk dan harga jualnya** ya. Contoh: "Tambah produk Kopi Susu harga 15000 modal 10000 stok 20 satuan pcs kategori Minuman".'];
            }

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
            $name = $args['name'];
            $hint = "Harga jual **{$name}** berapa Rupiah? Contoh: \"{$name} harga 15000\". Tambahkan modal/stok/satuan juga jika ada.";

            if ($hasCreateIntent) {
                return ['text' => $hint];
            }

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
                '%d. **%s** — %s terjual (%s)%s',
                $index + 1,
                $product['product_name'],
                AiMetrics::number($product['quantity_sold'], 2),
                $product['revenue_formatted'],
                isset($product['margin_pct']) ? ', margin '.AiMetrics::number($product['margin_pct']).'%' : '',
            ))->implode("\n");

            return "{$message}\n\n{$products}";
        }

        if ($toolName === 'get_barber_commissions') {
            return $message;
        }

        if ($toolName === 'check_stock' && ! empty($data['products'])) {
            $products = collect($data['products'])->map(fn (array $product) => sprintf(
                '- **%s** — %s %s%s%s',
                $product['name'],
                $product['stock'],
                $product['unit'],
                ! empty($product['min_stock_level']) ? ' (min '.$product['min_stock_level'].')' : '',
                match (true) {
                    ($product['stock_tracked'] ?? true) === false => ' · stok tidak dilacak',
                    ! empty($product['is_out_of_stock']) => ' · **habis**',
                    default => '',
                },
            ))->implode("\n");

            return "{$message}\n\n{$products}";
        }

        if ($toolName === 'detect_transaction_anomalies') {
            return $this->formatAnomalyDetails($data, $message);
        }

        return $message;
    }

    /**
     * Rincian tiap temuan audit transaksi dalam tabel Markdown.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatAnomalyDetails(array $data, string $message): string
    {
        $sections = [
            $message,
            '**Periode:** '.$data['date_from'].' — '.$data['date_to'],
        ];

        if (! empty($data['discount_anomalies'])) {
            $rows = collect($data['discount_anomalies'])->map(fn (array $row, int $index) => sprintf(
                '| %d | %s | %s | %s | %s%% | Rp %s |',
                $index + 1,
                $row['transaction_number'],
                $row['cashier'],
                $row['created_at'],
                $row['discount_pct'],
                number_format((float) $row['discount_amount'], 0, ',', '.'),
            ))->implode("\n");

            $sections[] = "### Diskon tidak wajar\n\n"
                ."| # | Transaksi | Kasir | Waktu | Diskon | Nilai diskon |\n"
                ."|---|---|---|---|---|---|\n"
                .$rows;
        }

        if (! empty($data['void_patterns'])) {
            $rows = collect($data['void_patterns'])->map(fn (array $row, int $index) => sprintf(
                '| %d | %s | %d kali | Rp %s |',
                $index + 1,
                $row['cashier'],
                $row['void_count'],
                number_format((float) $row['total_void_amount'], 0, ',', '.'),
            ))->implode("\n");

            $sections[] = "### Void berulang\n\n"
                ."| # | Kasir | Jumlah void | Nilai void |\n"
                ."|---|---|---|---|\n"
                .$rows;
        }

        if (! empty($data['cash_differences'])) {
            $rows = collect($data['cash_differences'])->map(fn (array $row, int $index) => sprintf(
                '| %d | %s | %s | Rp %s | Rp %s | **Rp %s** |',
                $index + 1,
                $row['cashier'],
                $row['closed_at'],
                number_format((float) $row['expected_cash'], 0, ',', '.'),
                number_format((float) $row['counted_cash'], 0, ',', '.'),
                number_format((float) $row['difference'], 0, ',', '.'),
            ))->implode("\n");

            $sections[] = "### Selisih kas shift\n\n"
                ."| # | Kasir | Ditutup | Seharusnya | Hitungan fisik | Selisih |\n"
                ."|---|---|---|---|---|---|\n"
                .$rows;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Jalankan tool yang diminta model, lalu biarkan model melanjutkan.
     *
     * Model boleh memanggil tool lagi (sampai MAX_TOOL_ROUNDS putaran) — mis.
     * cek penjualan lalu cek stok produk penyebabnya — sebelum menulis jawaban.
     * Sebelumnya hanya satu putaran, sehingga pertanyaan yang butuh dua sumber
     * data dijawab setengah atau ditebak.
     */
    protected function handleToolCalls(AiConversation $conversation, AiMessage $assistantMessage, AiResponse $response, ?callable $onDelta = null): array
    {
        $toolResults = [];
        $tools = $this->toolRegistry->getToolDefinitions();

        for ($round = 1; ; $round++) {
            foreach ($response->getToolCalls() as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $toolArgs = json_decode($toolCall['function']['arguments'] ?? '', true);
                $toolArgs = is_array($toolArgs) ? $toolArgs : [];
                $toolCallId = $toolCall['id'];

                $result = $this->toolRegistry->execute($toolName, $toolArgs, $this->user);

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'tool',
                    'content' => $result->toJson(),
                    'tool_call_id' => $toolCallId,
                ]);

                $this->logAudit($toolName, $toolArgs, $result);

                $toolResults[] = [
                    'tool_call_id' => $toolCallId,
                    'name' => $toolName,
                    'result' => $result->toArray(),
                ];
            }

            $isLastRound = $round >= self::MAX_TOOL_ROUNDS;
            $messages = $this->buildMessages($conversation);
            $nextTools = $isLastRound ? [] : $tools;

            $next = $onDelta
                ? $this->provider->chatStream($messages, $nextTools, $onDelta)
                : $this->provider->chat($messages, $nextTools);

            if (! $next->isSuccess()) {
                return [
                    'success' => false,
                    'error' => $next->error,
                    'conversation_id' => $conversation->id,
                    'tool_results' => $toolResults,
                    'status' => 502,
                ];
            }

            if (! $isLastRound && $next->hasToolCalls()) {
                $confirmation = $this->findConfirmationForToolCalls($next->getToolCalls());

                if ($confirmation) {
                    return [
                        'success' => true,
                        'conversation_id' => $conversation->id,
                        'confirmation' => $confirmation,
                        'tool_results' => $toolResults,
                    ];
                }

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $next->getContent(),
                    'tool_calls' => $next->getToolCalls(),
                    'tokens_used' => $next->tokensUsed,
                ]);

                $response = $next;

                continue;
            }

            $content = $this->cleanAssistantContent($next->getContent());

            $finalMessage = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $content,
                'tokens_used' => $next->tokensUsed,
            ]);

            return [
                'success' => true,
                'conversation_id' => $conversation->id,
                'message' => [
                    'id' => $finalMessage->id,
                    'role' => 'assistant',
                    'content' => $content,
                    'timestamp' => $finalMessage->created_at->format('H:i'),
                ],
                'tool_results' => $toolResults,
            ];
        }
    }

    /**
     * Rapikan teks jawaban model: buang pseudo tool-call yang bocor sebagai
     * teks (mis. "<tool_call>update_product…") dan jangan pernah mengirim
     * jawaban kosong ke user.
     */
    protected function cleanAssistantContent(?string $content): string
    {
        $content = (string) $content;
        $content = (string) preg_replace('/<tool_call>.*?(?:<\/tool_call>|$)/su', '', $content);
        $content = trim($content);

        if ($content === '') {
            return 'Maaf, saya belum berhasil menyusun jawaban untuk itu. Coba ulangi dengan lebih spesifik — misalnya sebutkan produk, periode (hari ini/minggu ini/bulan lalu), atau data yang ingin dilihat (penjualan, stok, laba).';
        }

        return $content;
    }

    protected function buildMessages(AiConversation $conversation): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->promptBuilder->buildSystemPrompt()],
        ];

        // Ambil 20 pesan terakhir untuk konteks
        // Diurutkan berdasarkan id: beberapa pesan (user, hasil tool, jawaban)
        // tersimpan pada detik yang sama, sehingga urutan created_at acak dan
        // model menerima riwayat yang teracak.
        $recentMessages = $conversation->messages()
            ->reorder()
            ->orderBy('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        // Riwayat tidak boleh diawali potongan pasangan tool (hasil tool tanpa
        // pemanggilnya), karena provider menolak/kebingungan.
        while ($recentMessages->isNotEmpty() && $recentMessages->first()->role === 'tool') {
            $recentMessages->shift();
        }

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
