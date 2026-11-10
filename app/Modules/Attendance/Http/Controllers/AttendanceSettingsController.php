<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Services\ActivityLogger;
use App\Services\AttendancePayrollAddOnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceSettingsController extends Controller
{
    /**
     * Halaman pengaturan absensi (lokasi kantor, radius, kebijakan).
     */
    public function settings()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        return Inertia::render('Attendance/Settings', [
            'settings' => AttendanceSettings::all($store),
            'package' => [
                'is_purchased' => AttendancePayrollAddOnService::isPurchased($store),
                'expires_at' => AttendancePayrollAddOnService::attendancePayrollUntil($store),
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            // Hari libur mulai awal tahun lalu agar riwayat tetap terlihat saat payroll Desember.
            'holidays' => Holiday::query()
                ->where('store_id', $store->id)
                ->whereDate('date', '>=', now()->subYear()->startOfYear()->toDateString())
                ->orderBy('date')
                ->get(['id', 'date', 'name'])
                ->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name])
                ->values(),
        ]);
    }

    public function update(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'required|integer|min:1|max:10000',
            'require_selfie' => 'boolean',
            'require_geolocation' => 'boolean',
            'grace_minutes' => 'integer|min:0|max:240',
            'overtime_requires_approval' => 'boolean',
            'late_deduction_method' => 'in:none,per_minute,per_day',
            'late_deduction_rate' => 'nullable|numeric|min:0',
        ]);

        $before = AttendanceSettings::all($store);
        AttendanceSettings::update($store, $validated);
        $after = AttendanceSettings::all($store->fresh());
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            ActivityLogger::log($store, 'attendance.settings_updated', 'Mengubah pengaturan absensi', $store, [
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ]);
        }

        return back()->with('success', 'Pengaturan absensi berhasil disimpan.');
    }

    private function currentStore(): Store
    {
        $store = auth()->user()->store ?? auth()->user()->stores()->first();

        if (! $store) {
            abort(404, 'Anda perlu membuat toko terlebih dahulu.');
        }

        return $store;
    }

    private function authorizeAdmin(): void
    {
        $role = auth()->user()->getRoleForActiveStore();

        if (! in_array($role, ['admin', 'manager', 'owner'], true)) {
            abort(403, 'Anda tidak memiliki akses ke pengaturan absensi.');
        }
    }
}
