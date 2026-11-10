<?php

namespace App\Http\Controllers;

use App\Exports\OwnerRevenueExport;
use App\Models\Store;
use App\Modules\Attendance\Services\HrOverviewService;
use App\Services\OwnerDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class OwnerDashboardController extends Controller
{
    public function __construct(
        protected OwnerDashboardService $service
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        $stores = $this->service->ownerStores($user);
        $period = $request->string('period', 'monthly')->toString();
        $startDate = $request->string('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'))->toString();
        $endDate = $request->string('end_date', Carbon::now()->format('Y-m-d'))->toString();
        $storeId = $request->integer('store_id');

        $chartData = $this->service->revenueData($user, $period, $startDate, $endDate, $storeId);

        return Inertia::render('Owner/Dashboard', [
            'stores' => $stores->values(),
            'chartData' => $chartData,
            // Ringkasan SDM lintas outlet (hanya outlet dengan modul absensi aktif).
            'hr' => app(HrOverviewService::class)->forStores(
                Store::query()->whereIn('id', $stores->pluck('id'))->where('is_active', true)->with('organization')->orderBy('name')->get()
            ),
            'filters' => [
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'store_id' => $storeId,
            ],
        ]);
    }

    public function revenue(Request $request)
    {
        $user = auth()->user();

        $period = $request->string('period', 'monthly')->toString();
        $startDate = $request->string('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'))->toString();
        $endDate = $request->string('end_date', Carbon::now()->format('Y-m-d'))->toString();
        $storeId = $request->integer('store_id');

        $data = $this->service->revenueData($user, $period, $startDate, $endDate, $storeId);

        return response()->json($data);
    }

    public function exportExcel(Request $request)
    {
        $user = auth()->user();

        $period = $request->string('period', 'monthly')->toString();
        $startDate = $request->string('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'))->toString();
        $endDate = $request->string('end_date', Carbon::now()->format('Y-m-d'))->toString();

        $data = $this->service->exportData($user, $period, $startDate, $endDate);
        $filename = 'laporan-pendapatan-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new OwnerRevenueExport($data), $filename);
    }
}
