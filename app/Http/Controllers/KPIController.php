<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\KPIService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KPIController extends Controller
{
    public function __construct(private KPIService $kpiService) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Hanya admin & owner yang boleh melihat KPI
        if (! $user->canManageStoreSettings() && ! $user->canAccessOwnerPanel() && ! $user->isSuperAdmin()) {
            abort(403, 'Akses ditolak. Hanya admin dan owner yang dapat melihat KPI.');
        }

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        // Outlet yang bisa diakses user
        $accessibleStoreIds = $user->isSuperAdmin()
            ? Store::pluck('id')->all()
            : $user->stores()->pluck('stores.id')->all();

        if (empty($accessibleStoreIds)) {
            abort(403, 'Tidak ada outlet yang dapat diakses.');
        }

        // Default: outlet aktif user, atau outlet pertama
        $storeId = (int) ($validated['store_id'] ?? $user->store_id ?? $accessibleStoreIds[0]);

        if (! in_array($storeId, $accessibleStoreIds)) {
            abort(403, 'Anda tidak memiliki akses ke outlet ini.');
        }

        $store = Store::findOrFail($storeId);
        $period = $validated['period'] ?? now()->format('Y-m');

        $kpi = $this->kpiService->calculateMonthlyKPI($store, $period);
        $comparison = $this->kpiService->compareWithTarget($store, $period);
        $topProducts = $this->kpiService->getTopProducts($store, $period);
        $alerts = $this->kpiService->getOperationalAlerts($store);

        // Daftar outlet untuk dropdown filter
        $stores = Store::query()
            ->whereIn('id', $accessibleStoreIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('KPI/Dashboard', [
            'stores' => $stores,
            'currentStore' => $store->only('id', 'name'),
            'period' => $period,
            'kpi' => $kpi,
            'comparison' => $comparison,
            'topProducts' => $topProducts,
            'alerts' => $alerts,
        ]);
    }

    /**
     * Settings KPI: atur growth percentage
     */
    public function settings(Request $request)
    {
        $user = $request->user();

        if (! $user->canManageStoreSettings() && ! $user->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'growth_percentage' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $store = Store::findOrFail($validated['store_id']);

        if (! $user->isMemberOfStore($store) && ! $user->isSuperAdmin()) {
            abort(403);
        }

        // Update target bulan depan, jika ada target bulan ini update juga jika diminta (opsional)
        // Di sini kita update target periode saat ini untuk simplicity
        $period = now()->format('Y-m');

        \App\Models\KpiTarget::updateOrCreate(
            [
                'store_id' => $store->id,
                'period' => $period,
            ],
            [
                'growth_percentage' => $validated['growth_percentage'],
                'auto_generated' => false,
            ]
        );

        return back()->with('success', "Target growth untuk periode berjalan diperbarui menjadi: {$validated['growth_percentage']}%");
    }
}
