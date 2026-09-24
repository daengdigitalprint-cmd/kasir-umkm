<?php

namespace App\Services\AI;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;

class AiPromptBuilder
{
    protected ?User $user;

    protected ?Store $store;

    public function __construct(?User $user = null, ?Store $store = null)
    {
        $this->user = $user;
        $this->store = $store;
    }

    public function buildSystemPrompt(): string
    {
        $basePrompt = config('ai.system_prompt');

        $context = $this->buildContext();

        return implode("\n\n", array_filter([
            $basePrompt,
            $context,
        ]));
    }

    protected function buildContext(): string
    {
        $parts = [];

        // Konteks waktu
        $parts[] = 'Waktu saat ini: '.Carbon::now()->format('d/m/Y H:i:s').' (WITA)';

        // Konteks user
        if ($this->user) {
            $parts[] = "User: {$this->user->name} ({$this->user->getRoleForActiveStore()})";
        }

        // Konteks store
        if ($this->store) {
            $parts[] = "Toko: {$this->store->name}";

            if ($this->store->address) {
                $parts[] = "Alamat: {$this->store->address}";
            }

            // Konteks multi-cabang: daftar outlet dalam grup usaha yang sama
            if ($this->store->organization_id) {
                $branchNames = Store::query()
                    ->where('organization_id', $this->store->organization_id)
                    ->where('is_active', true)
                    ->pluck('name');

                if ($branchNames->count() > 1) {
                    $parts[] = 'Outlet: '.implode(', ', $branchNames->all())
                        ." (total {$branchNames->count()} outlet aktif — usaha multi-cabang)";
                    $parts[] = 'Jika user meminta data "semua cabang/semua outlet", jalankan tool laporan (get_sales_insight, get_sales_summary, get_top_products) dengan scope "all" agar seluruh outlet digabung. Tanpa permintaan eksplisit, gunakan scope "current" (outlet aktif saja).';
                }
            }
        }

        // Instruksi format
        $parts[] = 'Format respons: Gunakan markdown untuk formatting. Angka gunakan format Indonesia (titik sebagai ribuan, koma untuk desimal).';

        $parts[] = 'ATURAN SAPAAN: sapaan atau basa-basi (halo, hai, apa kabar, terima kasih, dll) WAJIB dibalas singkat dan ramah TANPA memanggil tool apa pun.';

        return implode("\n", $parts);
    }

    public function buildWithContext(string $additionalContext): string
    {
        return $this->buildSystemPrompt()."\n\nKonteks tambahan:\n".$additionalContext;
    }

    public function buildToolDescriptionsPrompt(array $tools): string
    {
        if (empty($tools)) {
            return '';
        }

        $lines = ['TOOLS:', ''];

        foreach ($tools as $tool) {
            $def = $tool->getDefinition();
            $fn = $def['function'];
            $desc = mb_substr($fn['description'], 0, 120);
            $lines[] = "- {$fn['name']}: {$desc}";

            $params = $fn['parameters']['properties'] ?? [];
            if (! empty($params)) {
                $lineParts = [];
                foreach ($params as $name => $param) {
                    $required = in_array($name, $fn['parameters']['required'] ?? []) ? '*' : '';
                    $enum = isset($param['enum']) ? ' ['.implode('|', $param['enum']).']' : '';
                    $lineParts[] = "{$name}{$required}{$enum}";
                }
                $lines[] = '  Parameters: '.implode(', ', $lineParts);
            }

            $lines[] = '';
        }

        $lines[] = 'TOOL CALL FORMAT:';
        $lines[] = 'Jika perlu menjalankan tool, HANYA balas dengan JSON berikut saja (tidak ada teks lain sama sekali):';
        $lines[] = '{"action":"use_tool","tool":"NAMA_TOOL","arguments":{"param":"nilai"}}';
        $lines[] = '';
        $lines[] = 'CONTOH — ketika user minta tambah produk, jawaban HARUS berupa JSON saja, tidak ada kalimat lain:';
        $lines[] = '{"action":"use_tool","tool":"create_product","arguments":{"name":"Teh Botol","price":5000,"cost":3500,"category":"Minuman","stock":50,"unit":"pcs"}}';
        $lines[] = '';
        $lines[] = 'CONTOH — ketika user minta beli/restock barang dari supplier, jawaban HARUS berupa JSON saja:';
        $lines[] = '{"action":"use_tool","tool":"create_purchase_order","arguments":{"items":[{"product_name":"Air Mineral 900ml","quantity":20,"unit":"pcs"}],"supplier_name":"Maju Jaya","payment_method":"cash"}}';
        $lines[] = '';
        $lines[] = 'PANDUAN PILIH TOOL (WAJIB pilih tool yang paling sesuai pertanyaan, jangan yang lain):';
        $lines[] = '- "kapan stok habis", "produk mana yang akan habis", "kapan harus restock", "prediksi stok" → predict_stock_runway';
        $lines[] = '- "stok sekarang", "stok rendah/menipis", "stok yang habis saat ini" → check_stock';
        $lines[] = '- "penjualan/omzet hari ini, kemarin, minggu ini, bulan ini" → get_sales_summary';
        $lines[] = '- "performa toko", "analisa toko", "kenapa penjualan naik/turun", "margin/laba kotor" → get_sales_insight';
        $lines[] = '- "prediksi penjualan", "forecast/ramalan omzet", "perkiraan penjualan minggu/bulan depan", "akan laku berapa" → forecast_sales';
        $lines[] = '- "produk terlaris/paling laku" → get_top_products';
        $lines[] = '- "analisa/data semua cabang, semua outlet, seluruh outlet, antar cabang" → get_sales_insight/get_sales_summary/get_top_products dengan scope "all" (gabung semua outlet grup usaha).';
        $lines[] = '- "pelanggan terbaik", "pelanggan mulai jarang", "poin loyalty" → get_customer_insight';
        $lines[] = '- "cari pelanggan", "data pelanggan Budi", "no telepon customer", "customer bernama ..." → search_customer';
        $lines[] = '- "riwayat aksi", "apa saja yang kamu ubah" → get_action_log';
        $lines[] = '- "beli/restock/pesan barang ke supplier" → create_purchase_order (BUKAN create_product).';
        $lines[] = '- "laba bersih/laba rugi/untung rugi/beban operasional/laporan keuangan" → get_profit_report';
        $lines[] = '- "rekomendasi restock/daftar belanja/perlu beli apa/saran pembelian/siapkan belanjaan" → recommend_restock';
        $lines[] = '- "daftar belanja supplier/belanja ke supplier/order ke supplier/total belanja per supplier/siapkan order supplier" → purchase_suggestion';
        $lines[] = '- "bandingkan cabang/ranking cabang/cabang mana yang paling .../performa antar outlet" → compare_branches;';
        $lines[] = '- "transaksi mencurigakan", "anomali/fraud/kecurangan kasir", "void berulang", "diskon aneh/tidak wajar", "selisih kas", "audit kasir" → detect_transaction_anomalies;';
        $lines[] = '- "saran/rekomendasi harga", "harga jual yang ideal/tepat", "berapa sebaiknya dijual", "harga sesuai HPP", "margin yang ideal" → recommend_price;';
        $lines[] = '- "produk bocor", "margin tipis", "produk boros", "cross-sell", "bundling", "sering dibeli bareng", "insight laba per produk", "produk mana yang rugi" → product_profit_insight;';
        $lines[] = '';
        $lines[] = 'ATURAN WAJIB (TIDAK BOLEH DILANGGAR):';
        $lines[] = '- Sapaan/basa-basi ("halo", "hai", "apa kabar", "terima kasih", dll) → balas ramah dan singkat. DILARANG memanggil tool untuk sapaan.';
        $lines[] = '- Jika user menyebut NAMA PRODUK + HARGA → LANGSUNG balas JSON create_product. DILARANG menulis kalimat apapun sebelum atau sesudah JSON.';
        $lines[] = '- Pesan "beli/beliin/pesan/restock barang" (apalagi ada "dari supplier" atau "bayar cash/kredit") → create_purchase_order. JANGAN pakai create_product.';
        $lines[] = '- create_product HANYA untuk menambah produk baru dan WAJIB menyertakan price. Jangan panggil create_product tanpa price.';
        $lines[] = '- DILARANG meminta formulir, konfirmasi, atau info tambahan jika minimal nama + harga sudah ada.';
        $lines[] = '- Ekstrak semua nilai dari kalimat user: price, cost, category, stock, unit.';
        $lines[] = '- Setelah tool selesai dieksekusi sistem, cukup balas hasil tool itu saja.';
        $lines[] = '- Jika baru saja create_product, JANGAN jalankan search_product atau bahas duplikat kecuali user minta.';
        $lines[] = '- DILARANG mengarang/menebak angka. Jika ada tool yang relevan, WAJIB pakai tool itu dulu, jangan menjawab dari ingatan.';
        $lines[] = '- Jangan menjawab topik lain yang tidak ditanyakan user.';

        return implode("\n", $lines);
    }
}
