<?php

namespace App\Http\Controllers;

use App\Support\CustomerSegments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F9 — Segmen Pelanggan: Setia, Baru, Lama tidak datang + tombol WhatsApp (wa.me).
 */
class CustomerSegmentController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->store;
        $settings = CustomerSegments::settings($store);
        $segment = in_array($request->input('segment'), array_keys(CustomerSegments::LABELS), true)
            ? $request->input('segment')
            : CustomerSegments::LOYAL;
        $search = trim((string) $request->input('search', ''));

        $counts = [];
        foreach (array_keys(CustomerSegments::LABELS) as $key) {
            $counts[$key] = CustomerSegments::applySegment(CustomerSegments::customersWithStats($store, $settings), $key, $settings)->count();
        }

        $query = CustomerSegments::applySegment(CustomerSegments::customersWithStats($store, $settings), $segment, $settings)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(customers.name) LIKE ?', [$term])->orWhere('customers.phone', 'like', "%{$search}%"));
            });

        // Lama tidak datang: yang paling lama absen di atas; lainnya: kunjungan terbanyak.
        $segment === CustomerSegments::LAPSED
            ? $query->orderBy('customer_stats.last_visit')
            : $query->orderByDesc('customer_stats.recent_visits')->orderByDesc('customer_stats.last_visit');

        $customers = $query->paginate(25)->withQueryString()->through(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'whatsapp' => CustomerSegments::whatsappNumber($c->phone),
            'visits' => (int) $c->visits,
            'recent_visits' => (int) $c->recent_visits,
            'first_visit' => \Illuminate\Support\Carbon::parse($c->first_visit)->toIso8601String(),
            'last_visit' => \Illuminate\Support\Carbon::parse($c->last_visit)->toIso8601String(),
            'spent' => (float) $c->spent,
            'points' => (int) ($c->points_balance ?? 0),
        ]);

        return Inertia::render('Customers/Segments', [
            'segment' => $segment,
            'labels' => CustomerSegments::LABELS,
            'counts' => $counts,
            'customers' => $customers,
            'settings' => $settings,
            'search' => $search,
            'store' => ['id' => $store->id, 'name' => $store->name],
            'canEditSettings' => $request->user()->canManageStoreSettings(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageStoreSettings(), 403);

        $validated = $request->validate([
            'loyal_visits' => ['required', 'integer', 'min:2', 'max:60'],
            'loyal_days' => ['required', 'integer', 'min:7', 'max:365'],
            'new_days' => ['required', 'integer', 'min:7', 'max:365'],
            'lapsed_min_visits' => ['required', 'integer', 'min:1', 'max:100'],
            'lapsed_days' => ['required', 'integer', 'min:7', 'max:730'],
            'messages' => ['required', 'array'],
            'messages.'.CustomerSegments::LOYAL => ['required', 'string', 'max:500'],
            'messages.'.CustomerSegments::NEW => ['required', 'string', 'max:500'],
            'messages.'.CustomerSegments::LAPSED => ['required', 'string', 'max:500'],
        ]);

        $store = $request->user()->store;
        $settings = $store->settings ?? [];
        $settings['customer_segments'] = $validated;
        $store->update(['settings' => $settings]);

        return back()->with('success', 'Pengaturan segmen pelanggan disimpan.');
    }
}
