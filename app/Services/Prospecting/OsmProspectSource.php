<?php

namespace App\Services\Prospecting;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengambil usaha dari OpenStreetMap (data terbuka, lisensi ODbL) lewat
 * Nominatim (cari wilayah kota) dan Overpass API (cari usaha di wilayah itu).
 * Gratis dan tanpa kunci API; hanya membaca, tidak pernah mengirim apa pun
 * ke usaha yang ditemukan.
 */
class OsmProspectSource
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /** Dicoba berurutan bila server utama sibuk. */
    private const OVERPASS_URLS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    private const MAX_RESULTS = 800;

    private const RETRY_PAUSE_MS = 3000;

    /** Batas tunggu satu server, dan batas total semua percobaan. */
    private const REQUEST_TIMEOUT_SECONDS = 70;

    private const TOTAL_BUDGET_SECONDS = 150;

    /**
     * Jenis usaha yang dikenali. Urutan penting: yang lebih spesifik dulu.
     * 'tags' = pasangan kunci OSM → nilai yang cocok.
     */
    public const CATEGORIES = [
        'kedai_kopi' => [
            'label' => 'Kedai kopi',
            'aliases' => ['kopi', 'coffee', 'cafe', 'kafe'],
            'tags' => ['amenity' => ['cafe']],
        ],
        'minuman' => [
            'label' => 'Minuman',
            'aliases' => ['minuman', 'boba', 'jus', 'es krim'],
            'tags' => ['amenity' => ['ice_cream', 'juice_bar'], 'shop' => ['beverages', 'tea', 'coffee']],
        ],
        'bakery' => [
            'label' => 'Roti & kue',
            'aliases' => ['roti', 'bakery', 'kue'],
            'tags' => ['shop' => ['bakery', 'pastry', 'confectionery']],
        ],
        'rumah_makan' => [
            'label' => 'Warung / rumah makan',
            'aliases' => ['makan', 'restoran', 'resto', 'kuliner', 'bakso', 'sate', 'nasi', 'ayam', 'seafood'],
            'tags' => ['amenity' => ['restaurant', 'fast_food', 'food_court']],
        ],
        'barbershop' => [
            'label' => 'Barbershop & salon',
            'aliases' => ['barber', 'pangkas', 'cukur', 'salon'],
            'tags' => ['shop' => ['hairdresser', 'beauty']],
        ],
        'laundry' => [
            'label' => 'Laundry',
            'aliases' => ['laundry', 'londri', 'binatu'],
            'tags' => ['shop' => ['laundry', 'dry_cleaning']],
        ],
        'bengkel' => [
            'label' => 'Bengkel',
            'aliases' => ['bengkel'],
            'tags' => ['shop' => ['car_repair', 'motorcycle_repair', 'motorcycle', 'tyres']],
        ],
        'apotek' => [
            'label' => 'Apotek',
            'aliases' => ['apotek', 'apotik', 'farmasi'],
            'tags' => ['amenity' => ['pharmacy'], 'shop' => ['chemist']],
        ],
        'toko_bangunan' => [
            'label' => 'Toko bangunan',
            'aliases' => ['bangunan', 'material'],
            'tags' => ['shop' => ['hardware', 'doityourself', 'building_materials']],
        ],
        'kelontong' => [
            'label' => 'Toko & kelontong',
            'aliases' => ['kelontong', 'sembako', 'minimarket', 'warung', 'toko'],
            'tags' => ['shop' => ['convenience', 'supermarket', 'general', 'kiosk', 'grocery']],
        ],
    ];

    /** Jaringan besar: sudah punya sistem kasir sendiri, bukan sasaran. */
    private const CHAINS = [
        'indomaret', 'alfamart', 'alfamidi', 'lawson', 'circle k', 'familymart', 'hypermart', 'lotte mart',
        'starbucks', 'kfc', 'mcdonald', 'burger king', 'pizza hut', 'domino', 'dunkin', 'j.co', 'jco',
        'chatime', 'mixue', 'kopi kenangan', 'janji jiwa', 'fore coffee', 'excelso', 'solaria', 'hokben',
        'richeese', 'wendy', 'a&w', 'texas chicken', 'breadtalk', 'holland bakery', 'kimia farma', 'k-24',
        'guardian', 'watsons', 'century', 'shell', 'pertamina', 'ace hardware', 'mitra10', 'superindo',
        'hero supermarket', 'transmart', 'carrefour', 'maxx coffee', 'tomoro', 'point coffee',
    ];

    /**
     * @return array{city: string, category: string, items: list<array<string, mixed>>, skipped_chains: int, skipped_unnamed: int}
     */
    public function search(string $keyword, string $city): array
    {
        $keyword = trim($keyword);
        $area = $this->resolveArea($city);
        $category = self::matchCategory($keyword);

        $elements = $this->runOverpass($this->buildQuery($area['area_id'], $category, $keyword));

        $items = [];
        $chains = 0;
        $unnamed = 0;

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = trim((string) ($tags['name'] ?? ''));

            if ($name === '') {
                $unnamed++;

                continue;
            }

            if (self::isChain($name, $tags)) {
                $chains++;

                continue;
            }

            $items[] = $this->normalize($element, $tags, $name, $category, $keyword, $area['name']);
        }

        return [
            'city' => $area['name'],
            'category' => $category['label'] ?? Str::ucfirst($keyword),
            'items' => $items,
            'skipped_chains' => $chains,
            'skipped_unnamed' => $unnamed,
        ];
    }

    /** @return array{label: string, aliases: list<string>, tags: array<string, list<string>>}|null */
    public static function matchCategory(string $keyword): ?array
    {
        $keyword = Str::lower($keyword);

        foreach (self::CATEGORIES as $category) {
            foreach ($category['aliases'] as $alias) {
                if (str_contains($keyword, $alias)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** Ubah nomor Indonesia apa pun ke bentuk 62xxxx; null bila tidak masuk akal. */
    public static function normalizePhone(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        // Beberapa nomor dipisah ; atau / — ambil yang pertama yang sah, utamakan seluler.
        $candidates = [];
        foreach (preg_split('/[;,\/]| atau /i', $raw) as $part) {
            $digits = preg_replace('/\D+/', '', $part);
            if ($digits === '') {
                continue;
            }
            if (str_starts_with($digits, '0')) {
                $digits = '62'.substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                $digits = '62'.$digits;
            }
            if (str_starts_with($digits, '62') && strlen($digits) >= 9 && strlen($digits) <= 15) {
                $candidates[] = $digits;
            }
        }

        foreach ($candidates as $candidate) {
            if (self::isMobile($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    public static function isMobile(?string $phone): bool
    {
        return $phone !== null && str_starts_with($phone, '628') && strlen($phone) >= 11;
    }

    /** Nama akun Instagram dari tag atau URL; null bila tidak ada. */
    public static function instagramHandle(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (blank($value)) {
                continue;
            }
            if (preg_match('~instagram\.com/([A-Za-z0-9._]{2,30})~i', $value, $m)) {
                return strtolower($m[1]);
            }
            if (! str_contains($value, '/') && preg_match('/^@?([A-Za-z0-9._]{2,30})$/', trim($value), $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /** @return array{area_id: int, name: string} */
    private function resolveArea(string $city): array
    {
        $response = $this->client(20)->get(self::NOMINATIM_URL, [
            'q' => trim($city),
            'countrycodes' => 'id',
            'format' => 'jsonv2',
            'limit' => 8,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Layanan pencari wilayah (Nominatim) sedang tidak bisa dihubungi. Coba lagi sebentar.');
        }

        foreach ($response->json() ?? [] as $place) {
            // Butuh batas wilayah (relation) supaya pencarian tidak keluar kota.
            if (($place['osm_type'] ?? '') === 'relation' && ($place['category'] ?? '') === 'boundary') {
                return [
                    'area_id' => 3600000000 + (int) $place['osm_id'],
                    'name' => (string) ($place['name'] ?? Str::title($city)),
                ];
            }
        }

        throw new RuntimeException("Kota \"{$city}\" tidak ditemukan. Coba nama resmi kota/kabupatennya, mis. \"Makassar\" atau \"Kabupaten Gowa\".");
    }

    /** @param array{label: string, aliases: list<string>, tags: array<string, list<string>>}|null $category */
    private function buildQuery(int $areaId, ?array $category, string $keyword): string
    {
        $lines = [];

        if ($category !== null) {
            foreach ($category['tags'] as $key => $values) {
                $lines[] = sprintf('nw["%s"~"^(%s)$"]["name"](area.a);', $key, implode('|', $values));
            }
        } else {
            // Jenis usaha tak dikenal: cari berdasarkan nama di semua tempat usaha.
            $safe = trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $keyword));
            if ($safe === '') {
                throw new RuntimeException('Kata kunci jenis usaha tidak boleh kosong.');
            }
            foreach (['amenity', 'shop', 'craft'] as $key) {
                $lines[] = sprintf('nw["name"~"%s",i]["%s"](area.a);', $safe, $key);
            }
        }

        return sprintf(
            "[out:json][timeout:60];\narea(%d)->.a;\n(\n%s\n);\nout center tags %d;",
            $areaId,
            implode("\n", $lines),
            self::MAX_RESULTS,
        );
    }

    /** @return list<array<string, mixed>> */
    private function runOverpass(string $query): array
    {
        // Server publik sering lambat atau menolak sesaat (429/502/504) saat
        // ramai. Coba tiap server bergiliran, tetapi berhenti sebelum total
        // waktu tunggu melewati anggaran supaya permintaan web tidak putus.
        $deadline = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        for ($round = 0; $round < 3; $round++) {
            foreach (self::OVERPASS_URLS as $url) {
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 10) {
                    break 2;
                }

                try {
                    $response = $this->client(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))->asForm()->post($url, ['data' => $query]);
                } catch (\Throwable) {
                    continue;
                }

                // Kueri yang kehabisan waktu tetap dijawab 200 dengan "remark" berisi galat.
                $failedRemark = str_contains(Str::lower((string) $response->json('remark')), 'error');

                if ($response->successful() && is_array($response->json('elements')) && ! $failedRemark) {
                    return $response->json('elements');
                }
            }

            usleep(self::RETRY_PAUSE_MS * 1000);
        }

        throw new RuntimeException('Server peta OpenStreetMap sedang sibuk atau lambat. Tunggu beberapa menit lalu coba lagi.');
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, string>  $tags
     * @param  array{label: string}|null  $category
     * @return array<string, mixed>
     */
    private function normalize(array $element, array $tags, string $name, ?array $category, string $keyword, string $city): array
    {
        $phone = self::normalizePhone(
            $tags['contact:whatsapp'] ?? $tags['whatsapp'] ?? $tags['contact:mobile'] ?? $tags['mobile']
                ?? $tags['contact:phone'] ?? $tags['phone'] ?? null
        );
        $website = $tags['website'] ?? $tags['contact:website'] ?? $tags['url'] ?? null;
        $instagram = self::instagramHandle($tags['contact:instagram'] ?? null, $tags['instagram'] ?? null, $website);

        $street = trim(($tags['addr:street'] ?? '').' '.($tags['addr:housenumber'] ?? ''));
        $address = $tags['addr:full'] ?? implode(', ', array_filter([
            $street,
            $tags['addr:suburb'] ?? $tags['addr:subdistrict'] ?? $tags['addr:village'] ?? null,
            $tags['addr:district'] ?? null,
        ]));

        return [
            'source' => 'osm',
            'source_id' => $element['type'].'/'.$element['id'],
            'name' => Str::limit($name, 147),
            'category' => $category['label'] ?? Str::limit(Str::ucfirst($keyword), 37),
            'keyword' => Str::limit($keyword, 77),
            'city' => $city,
            'address' => $address !== '' ? Str::limit($address, 250) : null,
            'lat' => $element['lat'] ?? $element['center']['lat'] ?? null,
            'lng' => $element['lon'] ?? $element['center']['lon'] ?? null,
            'phone' => $phone,
            'phone_is_mobile' => self::isMobile($phone),
            'website' => filled($website) ? Str::limit($website, 250, '') : null,
            'instagram' => $instagram,
            'has_hours' => filled($tags['opening_hours'] ?? null),
        ];
    }

    /** @param array<string, string> $tags */
    private static function isChain(string $name, array $tags): bool
    {
        if (isset($tags['brand:wikidata'])) {
            return true;
        }

        $haystack = Str::lower($name.' '.($tags['brand'] ?? ''));
        foreach (self::CHAINS as $chain) {
            if (str_contains($haystack, $chain)) {
                return true;
            }
        }

        return false;
    }

    private function client(int $timeout): PendingRequest
    {
        // Nama klien generik: tidak membawa identitas pengguna atau produk.
        return Http::timeout($timeout)->withUserAgent('prospect-finder/1.0')->acceptJson();
    }
}
