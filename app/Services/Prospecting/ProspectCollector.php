<?php

namespace App\Services\Prospecting;

use App\Models\Prospect;
use App\Support\ProspectScorer;
use Illuminate\Support\Str;

/**
 * Menjalankan satu pencarian (jenis usaha + kota) dan menyimpan hasilnya
 * tanpa duplikat. Status dan catatan tindak lanjut yang sudah diisi tim
 * tidak pernah ditimpa saat pencarian diulang.
 */
class ProspectCollector
{
    public function __construct(private OsmProspectSource $source) {}

    /**
     * @return array{keyword: string, city: string, category: string, found: int, added: int, updated: int, duplicates: int, with_phone: int, skipped_chains: int}
     */
    public function collect(string $keyword, string $city): array
    {
        $result = $this->source->search($keyword, $city);

        $added = $updated = $duplicates = $withPhone = 0;

        foreach ($result['items'] as $item) {
            $hasHours = (bool) ($item['has_hours'] ?? false);
            unset($item['has_hours']);

            $item['score'] = ProspectScorer::score($item + ['has_hours' => $hasHours]);
            $item['dedupe_key'] = self::dedupeKey($item);
            $item['last_seen_at'] = now();

            $existing = Prospect::where('source', $item['source'])->where('source_id', $item['source_id'])->first();

            if ($existing) {
                // Segarkan data usaha; kategori/kata kunci pertama dan tindak lanjut dipertahankan.
                unset($item['category'], $item['keyword']);
                $existing->update($item);
                $updated++;
            } elseif (Prospect::where('dedupe_key', $item['dedupe_key'])->exists()) {
                // Usaha yang sama tercatat dua kali di sumbernya (mis. titik + bangunan).
                $duplicates++;

                continue;
            } else {
                Prospect::create($item);
                $added++;
            }

            if ($item['phone'] !== null) {
                $withPhone++;
            }
        }

        return [
            'keyword' => $keyword,
            'city' => $result['city'],
            'category' => $result['category'],
            'found' => count($result['items']),
            'added' => $added,
            'updated' => $updated,
            'duplicates' => $duplicates,
            'with_phone' => $withPhone,
            'skipped_chains' => $result['skipped_chains'],
        ];
    }

    /**
     * Nomor telepon adalah penanda terkuat; tanpa nomor, pakai nama + lokasi
     * (dibulatkan ±100 m) dalam kota yang sama.
     *
     * @param  array<string, mixed>  $item
     */
    public static function dedupeKey(array $item): string
    {
        if (filled($item['phone'] ?? null)) {
            return 'p:'.$item['phone'];
        }

        $location = isset($item['lat'], $item['lng'])
            ? round((float) $item['lat'], 3).','.round((float) $item['lng'], 3)
            : '-';

        return Str::limit('n:'.Str::slug($item['city'].' '.$item['name']).':'.$location, 187, '');
    }
}
