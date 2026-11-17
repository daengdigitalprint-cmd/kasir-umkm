<?php

namespace App\Http\Controllers;

use App\Exports\StockMonitorExport;
use App\Services\MultiLocationStockService;
use App\Support\StoreLocationType;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * F7 — Pantau Stok Semua Lokasi (owner, Gudang Pusat Pro).
 */
class StockMonitorController extends Controller
{
    public function __construct(private readonly MultiLocationStockService $service) {}

    public function index(Request $request): Response
    {
        [$search, $problemsOnly] = $this->filters($request);
        $stores = $this->service->storesFor($request->user());
        $rows = $this->service->rows($stores, $search, $problemsOnly);

        $perPage = 50;
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('StockMonitor/Index', [
            'stores' => $stores->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_central_warehouse' => StoreLocationType::isCentralWarehouse($s),
            ])->values(),
            'rows' => $paginator,
            'summary' => [
                'products' => count($rows),
                'out' => $this->countCells($rows, MultiLocationStockService::STATUS_OUT),
                'low' => $this->countCells($rows, MultiLocationStockService::STATUS_LOW),
            ],
            'filters' => ['search' => $search ?? '', 'problems' => $problemsOnly],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$search, $problemsOnly] = $this->filters($request);
        $stores = $this->service->storesFor($request->user());
        $rows = $this->service->rows($stores, $search, $problemsOnly);

        return Excel::download(
            new StockMonitorExport($stores, $rows),
            'stok-semua-lokasi-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    /** @return array{0: string|null, 1: bool} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'problems' => ['nullable', 'boolean'],
        ]);

        return [$validated['search'] ?? null, (bool) ($validated['problems'] ?? false)];
    }

    private function countCells(array $rows, string $status): int
    {
        $count = 0;
        foreach ($rows as $row) {
            foreach ($row['locations'] as $cell) {
                if ($cell !== null && $cell['status'] === $status) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
