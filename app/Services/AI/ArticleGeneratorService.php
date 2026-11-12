<?php

namespace App\Services\AI;

use App\Models\AiConfiguration;
use App\Models\AiSetting;
use App\Models\Article;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Str;

/**
 * Generator artikel SEO via AI (dipakai Super Admin).
 *
 * Memakai konfigurasi AI global yang sama dengan fitur AI lain
 * (ai_configurations / ai_settings / .env), tetapi dengan max_tokens
 * lebih besar karena artikel panjang.
 */
class ArticleGeneratorService
{
    protected AiProviderInterface $provider;

    protected string $modelId = '';

    public function __construct()
    {
        $this->provider = $this->resolveProvider();
    }

    protected function resolveProvider(): AiProviderInterface
    {
        // Artikel butuh output panjang & waktu lebih lama dari chat biasa.
        config(['ai.timeout' => 300]);

        $config = AiConfiguration::query()
            ->active()
            ->whereNull('store_id')
            ->first();

        if ($config) {
            $this->modelId = (string) $config->model_id;

            return new OpenAiCompatibleProvider([
                'base_url' => $config->base_url,
                'model_id' => $config->model_id,
                'api_key' => $config->api_key,
                'max_tokens' => max((int) $config->max_tokens, 8000),
                'temperature' => 0.7,
                'supports_function_calling' => false,
            ]);
        }

        // Fallback: pengaturan AI dari halaman Super Admin, lalu .env
        AiSetting::applyToRuntime();

        $providerName = config('ai.default_provider', 'openai');
        $providerConfig = (array) config("ai.providers.{$providerName}", config('ai.providers.openai'));

        $providerConfig['max_tokens'] = max((int) ($providerConfig['max_tokens'] ?? 0), 8000);
        $providerConfig['temperature'] = 0.7;
        $providerConfig['supports_function_calling'] = false;

        $this->modelId = (string) ($providerConfig['model_id'] ?? '');

        return new OpenAiCompatibleProvider($providerConfig);
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    /**
     * Generate satu artikel SEO lengkap.
     *
     * @param  array{keyword: string, strategy: string, audience?: ?string, length?: ?string, outline?: ?string, category?: ?string}  $input
     * @return array{title: string, slug: string, meta_title: string, meta_description: string, excerpt: string, category: string, tags: array, content_markdown: string, faq: array}
     *
     * @throws \RuntimeException jika AI gagal atau respons tidak valid
     */
    public function generateArticle(array $input): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->articleSystemPrompt()],
            ['role' => 'user', 'content' => $this->articleUserPrompt($input)],
        ];

        $response = $this->provider->chat($messages);

        if (! $response->isSuccess()) {
            throw new \RuntimeException($response->error ?? 'AI tidak merespons.');
        }

        $data = $this->parseJson((string) $response->getContent());

        foreach (['title', 'meta_title', 'meta_description', 'excerpt', 'content_markdown'] as $required) {
            if (empty($data[$required]) || ! is_string($data[$required])) {
                throw new \RuntimeException("Respons AI tidak lengkap (field '{$required}' kosong). Coba generate ulang.");
            }
        }

        // Normalisasi field opsional
        $data['tags'] = array_values(array_filter(array_map(
            fn ($t) => is_string($t) ? trim($t) : null,
            (array) ($data['tags'] ?? []),
        )));

        $data['faq'] = array_values(array_filter(array_map(function ($f) {
            if (is_array($f) && ! empty($f['question']) && ! empty($f['answer'])) {
                return ['question' => (string) $f['question'], 'answer' => (string) $f['answer']];
            }

            return null;
        }, (array) ($data['faq'] ?? []))));

        $data['category'] = is_string($data['category'] ?? null) && $data['category'] !== ''
            ? Str::limit(trim($data['category']), 100, '')
            : ($input['category'] ?? null);

        // Batas panjang meta sesuai standar SERP
        $data['meta_title'] = Str::limit(trim($data['meta_title']), 60, '');
        $data['meta_description'] = Str::limit(trim($data['meta_description']), 158, '');

        return $data;
    }

    /**
     * Saran topik artikel baru seputar aplikasi kasir / UMKM.
     *
     * @return array<int, array{title: string, strategy: string, keyword: string, reason: string}>
     */
    public function suggestTopics(?string $focus = null): array
    {
        $existing = Article::query()
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('title')
            ->all();

        $existingList = $existing === []
            ? '(belum ada artikel)'
            : '- '.implode("\n- ", $existing);

        $focusLine = $focus !== null && trim($focus) !== ''
            ? "Fokuskan ide pada tema: {$focus}.\n"
            : '';

        $prompt = <<<PROMPT
Kamu adalah ahli strategi konten SEO untuk "Kasir UMKM by MaccaPOS", aplikasi kasir (POS) online untuk UMKM Indonesia (warung makan, laundry, toko kelontong, apotek, dll). Fitur utamanya: pencatatan penjualan, kelola stok, cetak struk, shift kasir, laporan keuangan, menu digital QR, absensi & gaji karyawan.

Usulkan 10 ide artikel blog BARU berbahasa Indonesia untuk menarik trafik organik pemilik UMKM. {$focusLine}
JANGAN mengusulkan topik yang sudah ada di daftar artikel berikut:
{$existingList}

Setiap ide harus memakai salah satu strategi: pillar, cluster, howto, listicle, comparison, faq, programmatic.

Balas HANYA dengan JSON array valid tanpa teks lain, format:
[{"title": "...", "strategy": "howto", "keyword": "kata kunci utama", "reason": "alasan singkat kenapa topik ini menarik trafik"}]
PROMPT;

        $response = $this->provider->chat([
            ['role' => 'user', 'content' => $prompt],
        ]);

        if (! $response->isSuccess()) {
            throw new \RuntimeException($response->error ?? 'AI tidak merespons.');
        }

        $data = $this->parseJson((string) $response->getContent());

        $topics = array_values(array_filter(array_map(function ($t) {
            if (is_array($t) && ! empty($t['title'])) {
                return [
                    'title' => (string) $t['title'],
                    'strategy' => (string) ($t['strategy'] ?? 'howto'),
                    'keyword' => (string) ($t['keyword'] ?? ''),
                    'reason' => (string) ($t['reason'] ?? ''),
                ];
            }

            return null;
        }, is_array($data) ? $data : [])));

        if ($topics === []) {
            throw new \RuntimeException('AI tidak menghasilkan saran topik yang valid. Coba lagi.');
        }

        return $topics;
    }

    /** Konversi markdown artikel ke HTML aman untuk halaman publik. */
    public static function markdownToHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    protected function articleSystemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah penulis konten SEO senior berbahasa Indonesia untuk blog "Kasir UMKM by MaccaPOS" — aplikasi kasir (POS) online untuk UMKM Indonesia. Fitur produk: pencatatan penjualan, kelola stok & stok opname, cetak struk (termasuk printer bluetooth), shift kasir, laporan keuangan & laba rugi, menu digital QR untuk pelanggan, piutang/hutang, absensi & penggajian karyawan, multi-outlet.

Aturan penulisan WAJIB:
1. Bahasa Indonesia yang natural dan mudah dipahami pemilik UMKM (bukan bahasa korporat kaku). Hindari kalimat yang terdengar seperti AI.
2. Konten HARUS memberi nilai nyata: angka konkret, contoh kasus UMKM Indonesia, langkah praktis yang bisa langsung dipraktikkan.
3. Struktur markdown: JANGAN tulis H1 (judul ditangani sistem). Mulai dengan 1-2 paragraf pembuka yang langsung menjawab intent pencarian, lalu heading H2 (##) dan H3 (###) yang mengandung variasi kata kunci secara natural.
4. Kata kunci utama muncul di: paragraf pertama, minimal 2 heading, dan tersebar natural (JANGAN keyword stuffing).
5. Panjang sesuai permintaan user. Paragraf pendek (2-4 kalimat), gunakan bullet list dan tabel markdown bila membantu.
6. Internal linking: jika diberikan daftar artikel lain, sisipkan 2-5 link internal yang BENAR-BENAR relevan dengan konteks kalimat, format markdown [anchor text](/blog/slug). Anchor text deskriptif, bukan "klik di sini". Hanya pakai slug dari daftar yang diberikan, JANGAN mengarang slug.
7. Sebut produk "Kasir UMKM by MaccaPOS" secara halus 1-2 kali bila relevan (soft-selling, bukan hard-selling), dengan link [Kasir UMKM by MaccaPOS](/).
8. Akhiri dengan heading "## Kesimpulan" berisi rangkuman singkat + ajakan halus mencoba aplikasi kasir.
9. JANGAN mengarang data statistik dengan sumber palsu. Boleh pakai estimasi umum dengan bahasa "umumnya", "rata-rata".

Balas HANYA dengan satu objek JSON valid (tanpa code fence, tanpa teks lain) dengan struktur:
{
  "title": "Judul artikel (H1, menarik, mengandung kata kunci, maksimal 65 karakter)",
  "meta_title": "Title tag SEO, maksimal 60 karakter, kata kunci di depan",
  "meta_description": "Meta description 140-155 karakter, mengandung kata kunci, ada ajakan klik",
  "excerpt": "Ringkasan 1-2 kalimat untuk kartu daftar artikel",
  "category": "Satu kategori singkat, contoh: Tips Kasir | Manajemen Stok | Keuangan UMKM | Pemasaran | Operasional",
  "tags": ["3-6 tag singkat"],
  "content_markdown": "Isi artikel lengkap dalam markdown sesuai aturan di atas",
  "faq": [{"question": "Pertanyaan yang sering dicari", "answer": "Jawaban ringkas 2-3 kalimat"}]
}
Sertakan 3-5 item FAQ yang relevan dengan kata kunci (untuk FAQ schema).
PROMPT;
    }

    /**
     * @param  array{keyword: string, strategy: string, audience?: ?string, length?: ?string, outline?: ?string, category?: ?string}  $input
     */
    protected function articleUserPrompt(array $input): string
    {
        $strategyGuides = [
            'pillar' => 'Strategi: ARTIKEL PILAR. Tulis panduan terlengkap tentang topik ini (ultimate guide). Cakup semua sub-topik penting secara menyeluruh agar jadi rujukan utama. Target 2000+ kata.',
            'cluster' => 'Strategi: ARTIKEL CLUSTER. Bahas satu sub-topik spesifik secara mendalam. Jika ada artikel pilar yang relevan di daftar internal link, WAJIB link ke artikel pilar tersebut di paragraf awal.',
            'howto' => 'Strategi: HOW-TO / TUTORIAL. Susun langkah-langkah bernomor yang jelas dan bisa langsung dipraktikkan. Setiap langkah punya heading H3 sendiri. Sertakan tips dan kesalahan umum.',
            'listicle' => 'Strategi: LISTICLE. Format daftar bernomor (judul mengandung angka). Setiap poin punya heading H2/H3, penjelasan konkret, dan contoh.',
            'comparison' => 'Strategi: PERBANDINGAN. Bandingkan opsi secara jujur dan berimbang, gunakan tabel perbandingan markdown, akhiri dengan rekomendasi untuk tiap jenis kebutuhan.',
            'faq' => 'Strategi: FAQ / LONG-TAIL. Jawab pertanyaan utama secara langsung di paragraf pertama (optimasi featured snippet), lalu bahas pertanyaan-pertanyaan turunan, masing-masing dengan heading H2 berbentuk pertanyaan.',
            'programmatic' => 'Strategi: PER JENIS USAHA. Tulis spesifik untuk jenis usaha yang disebut di kata kunci (contoh: warung makan, laundry, apotek). Bahas masalah khas usaha itu dan bagaimana aplikasi kasir menyelesaikannya.',
        ];

        $guide = $strategyGuides[$input['strategy']] ?? $strategyGuides['howto'];

        $lengthMap = [
            'short' => 'Panjang artikel: 800-1200 kata.',
            'medium' => 'Panjang artikel: 1200-1800 kata.',
            'long' => 'Panjang artikel: 1800-2500 kata.',
        ];
        $length = $lengthMap[$input['length'] ?? 'medium'] ?? $lengthMap['medium'];

        $audience = ! empty($input['audience'])
            ? "Target pembaca: {$input['audience']}."
            : 'Target pembaca: pemilik UMKM Indonesia yang belum terlalu paham teknologi.';

        $outline = ! empty($input['outline'])
            ? "Gunakan kerangka berikut sebagai acuan outline:\n{$input['outline']}"
            : '';

        $categoryLine = ! empty($input['category'])
            ? "Kategori artikel: {$input['category']}."
            : '';

        // Daftar artikel yang ada untuk internal linking
        $existing = Article::query()
            ->published()
            ->orderByDesc('is_pillar')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get(['title', 'slug', 'is_pillar', 'category']);

        $linkList = $existing->isEmpty()
            ? 'Belum ada artikel lain — jangan membuat internal link ke /blog/ apa pun.'
            : "Daftar artikel yang tersedia untuk internal link (format: judul => slug):\n".$existing
                ->map(fn ($a) => "- {$a->title} => {$a->slug}".($a->is_pillar ? ' (PILAR)' : ''))
                ->implode("\n");

        return <<<PROMPT
Tulis artikel SEO dengan ketentuan:

Kata kunci utama: {$input['keyword']}
{$guide}
{$length}
{$audience}
{$categoryLine}
{$outline}

{$linkList}
PROMPT;
    }

    /** Parse JSON dari respons AI (toleran terhadap code fence dan teks pembuka). */
    protected function parseJson(string $content): array
    {
        $content = trim($content);

        // Buang code fence ```json ... ```
        $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = (string) preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Ambil blok JSON pertama ({...} atau [...]) dari teks
        $firstBrace = strpos($content, '{');
        $firstBracket = strpos($content, '[');

        $start = false;
        $openChar = '{';
        $closeChar = '}';

        if ($firstBracket !== false && ($firstBrace === false || $firstBracket < $firstBrace)) {
            $start = $firstBracket;
            $openChar = '[';
            $closeChar = ']';
        } elseif ($firstBrace !== false) {
            $start = $firstBrace;
        }

        if ($start !== false) {
            $end = strrpos($content, $closeChar);

            if ($end !== false && $end > $start) {
                $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        throw new \RuntimeException('Respons AI bukan JSON valid. Coba generate ulang atau ganti model AI yang lebih baik di Pengaturan AI.');
    }
}
