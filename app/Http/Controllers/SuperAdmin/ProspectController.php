<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Services\Prospecting\OsmProspectSource;
use App\Services\Prospecting\ProspectCollector;
use App\Support\ProspectScorer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prospek penjualan dari sumber data terbuka: cari, daftar, tindak lanjut,
 * ekspor. Alat ini hanya mengumpulkan dan menampilkan — menghubungi prospek
 * selalu dilakukan manusia, satu per satu.
 */
class ProspectController extends Controller
{
    public function index(Request $request): Response
    {
        $prospects = $this->filtered($request)
            // Yang belum dihubungi dan paling layak tampil paling atas.
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'interested' THEN 1 WHEN 'contacted' THEN 2 ELSE 3 END")
            ->orderByDesc('score')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Prospect $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'city' => $p->city,
                'address' => $p->address,
                'phone' => $p->phone,
                'phone_local' => $p->phoneLocal(),
                'phone_is_mobile' => $p->phone_is_mobile,
                'website' => $p->website,
                'instagram' => $p->instagram,
                'rating' => $p->rating,
                'reviews_count' => $p->reviews_count,
                'score' => $p->score,
                'tier' => $p->tier(),
                'tier_label' => ProspectScorer::tierLabel($p->score),
                'status' => $p->status,
                'notes' => $p->notes,
                'source' => Prospect::SOURCES[$p->source] ?? $p->source,
                'contacted_at' => $p->contacted_at?->toISOString(),
            ]);

        return Inertia::render('SuperAdmin/Prospects/Index', [
            'prospects' => $prospects,
            'stats' => [
                'total' => Prospect::count(),
                'with_phone' => Prospect::whereNotNull('phone')->count(),
                'to_contact' => Prospect::whereNotNull('phone')->where('status', 'new')->count(),
                'interested' => Prospect::where('status', 'interested')->count(),
            ],
            'statuses' => Prospect::STATUSES,
            'cities' => Prospect::query()->distinct()->orderBy('city')->pluck('city'),
            'categories' => Prospect::query()->distinct()->orderBy('category')->pluck('category'),
            'suggestions' => collect(OsmProspectSource::CATEGORIES)->pluck('label')->values(),
            'searchResult' => session('prospect_search_result'),
            'filters' => [
                'q' => $request->query('q'),
                'city' => $request->query('city'),
                'category' => $request->query('category'),
                'status' => $request->query('status'),
                'contact' => $request->query('contact'),
            ],
        ]);
    }

    /** Jalankan satu pencarian: jenis usaha + kota. */
    public function search(Request $request, ProspectCollector $collector): RedirectResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'min:3', 'max:80'],
            'city' => ['required', 'string', 'min:3', 'max:100'],
        ], [
            'keyword.required' => 'Isi jenis usaha, mis. kedai kopi.',
            'city.required' => 'Isi nama kota, mis. Makassar.',
        ]);

        // Server peta publik bisa butuh puluhan detik untuk kota besar.
        set_time_limit(240);

        try {
            $result = $collector->collect($data['keyword'], $data['city']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['city' => $e->getMessage()]);
        }

        return redirect()
            ->route('superadmin.prospects.index', ['city' => $result['city'], 'category' => $result['category']])
            ->with('prospect_search_result', $result);
    }

    public function update(Request $request, Prospect $prospect): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(array_keys(Prospect::STATUSES))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['status']) && $data['status'] !== 'new' && $prospect->contacted_at === null) {
            $data['contacted_at'] = now();
        }

        $prospect->update($data);

        return back();
    }

    /** Hapus permanen, mis. bila pemilik usaha meminta datanya dihapus. */
    public function destroy(Prospect $prospect): RedirectResponse
    {
        $prospect->delete();

        return back();
    }

    /** CSV (dibuka Excel) — mengikuti filter yang sedang aktif. */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderByDesc('score')->orderBy('name');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM agar Excel membaca UTF-8 dengan benar.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Nama usaha', 'Kategori', 'Kota', 'Alamat', 'Telepon', 'Seluler/WA', 'Instagram', 'Situs',
                'Rating', 'Jumlah ulasan', 'Skor', 'Prioritas', 'Status', 'Catatan', 'Sumber',
            ]);

            $query->chunk(500, function ($prospects) use ($out) {
                foreach ($prospects as $p) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $p->name,
                        $p->category,
                        $p->city,
                        $p->address,
                        $p->phoneLocal(),
                        $p->phone_is_mobile ? 'ya' : '',
                        $p->instagram ? '@'.$p->instagram : '',
                        $p->website,
                        $p->rating,
                        $p->reviews_count,
                        $p->score,
                        ProspectScorer::tierLabel($p->score),
                        Prospect::STATUSES[$p->status] ?? $p->status,
                        $p->notes,
                        Prospect::SOURCES[$p->source] ?? $p->source,
                    ]));
                }
            });

            fclose($out);
        }, 'prospek-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request): Builder
    {
        return Prospect::query()
            ->when($request->query('q'), fn ($q, $search) => $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('instagram', 'like', "%{$search}%");

                $digits = preg_replace('/\D+/', '', (string) $search);
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%');
                }
            }))
            ->when($request->query('city'), fn ($q, $city) => $q->where('city', $city))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('contact') === 'mobile', fn ($q) => $q->where('phone_is_mobile', true))
            ->when($request->query('contact') === 'phone', fn ($q) => $q->whereNotNull('phone'))
            ->when($request->query('contact') === 'any', fn ($q) => $q->where(
                fn ($w) => $w->whereNotNull('phone')->orWhereNotNull('instagram')->orWhereNotNull('website')
            ));
    }

    /**
     * Data berasal dari sumber publik yang bisa disunting siapa saja: awalan
     * = + - @ bisa dijalankan Excel sebagai rumus (CSV injection).
     */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
