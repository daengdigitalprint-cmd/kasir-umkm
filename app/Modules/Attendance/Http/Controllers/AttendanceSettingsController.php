<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Modules\Attendance\Support\AttendanceSettings;
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

        AttendanceSettings::update($store, $validated);

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
