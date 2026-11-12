<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TableController extends Controller
{
    private function guardStore(): array
    {
        $store = auth()->user()->store;

        if (! $store) {
            return [null, redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.')];
        }

        if (! \App\Support\PlanModules::settingOn($store, 'scan_meja_enabled')) {
            return [null, redirect()->route('dashboard')
                ->with('warning', 'Modul Scan Meja belum diaktifkan. Aktifkan di Pengaturan → Toko → Modul Fitur.')];
        }

        return [$store, null];
    }

    public function index()
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $tables = Table::forStore($store->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Table $t) => $this->serialize($t));

        return Inertia::render('ScanMeja/Tables', [
            'tables' => $tables,
        ]);
    }

    public function store(Request $request)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'nullable|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:99',
            'location' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $table = $store->tables()->create([
            ...$validated,
            'is_active' => true,
        ]);

        // Generate QR code
        try {
            QrCodeService::generateForTable($table);
        } catch (\Exception $e) {
            \Log::warning('Gagal generate QR code: '.$e->getMessage());
        }

        return back()->with('status', 'table-created');
    }

    public function update(Request $request, Table $table)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($table->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'code' => 'nullable|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:99',
            'location' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $table->update($validated);

        return back()->with('status', 'table-updated');
    }

    public function toggleActive(Table $table)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($table->store_id !== $store->id) {
            abort(404);
        }

        $table->update(['is_active' => ! $table->is_active]);

        return back()->with('status', $table->is_active ? 'table-activated' : 'table-deactivated');
    }

    public function destroy(Table $table)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($table->store_id !== $store->id) {
            abort(404);
        }

        $table->delete();

        return back()->with('status', 'table-deleted');
    }

    public function regenerateQr(Table $table)
    {
        [$store, $redirect] = $this->guardStore();
        if ($redirect) {
            return $redirect;
        }

        if ($table->store_id !== $store->id) {
            abort(404);
        }

        try {
            $url = QrCodeService::regenerate($table);

            return back()->with('status', 'qr-regenerated');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal generate QR code: '.$e->getMessage());
        }
    }

    private function serialize(Table $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'code' => $t->code,
            'slug' => $t->slug,
            'qr_code_url' => $t->qr_code_url,
            'is_active' => $t->is_active,
            'capacity' => $t->capacity,
            'location' => $t->location,
            'notes' => $t->notes,
            'guest_url' => $t->guestUrl(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
