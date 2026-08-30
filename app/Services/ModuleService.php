<?php

namespace App\Services;

use App\Models\ModuleActivityLog;
use App\Models\Store;
use App\Models\User;

class ModuleService
{
    /**
     * Daftar modul yang didukung oleh sistem.
     * Key = nama modul (disimpan di settings), Value = label untuk logging.
     */
    public const MODULES = [
        'scan_meja_enabled' => 'Scan Meja (Menu Digital)',
        'queue_module_enabled' => 'Modul Antrian',
        'wholesale_enabled' => 'Modul Harga Grosir',
        'attendance_enabled' => 'Modul Absensi',
        'recipes_enabled' => 'Menu Resep',
        'productions_enabled' => 'Menu Produksi',
        'kitchen_queue_enabled' => 'Antrian Dapur',
        'accounting_enabled' => 'Modul Akuntansi (Jurnal)',
        'receivables_enabled' => 'Modul Hutang Piutang',
    ];

    /**
     * Aktifkan atau nonaktifkan modul untuk store tertentu.
     */
    public static function toggle(Store $store, string $moduleName, bool $enabled, ?User $user = null, ?string $keterangan = null): void
    {
        $settings = $store->settings ?? [];
        $statusSebelum = (bool) ($settings[$moduleName] ?? false);

        // Jangan lakukan apa-apa jika status tidak berubah
        if ($statusSebelum === $enabled) {
            return;
        }

        // Simpan status ke settings JSON
        $settings[$moduleName] = $enabled;
        $store->update(['settings' => $settings]);

        // Catat perubahan ke module_activity_logs
        $userId = $user instanceof User ? $user->id : ($user ?? auth()->id());
        $label = self::MODULES[$moduleName] ?? $moduleName;

        ModuleActivityLog::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'module_name' => $moduleName,
            'action' => $enabled ? 'enabled' : 'disabled',
            'status_sebelum' => $statusSebelum,
            'status_sesudah' => $enabled,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'keterangan' => $keterangan ?? "Modul \"{$label}\" ".($enabled ? 'diaktifkan' : 'dinonaktifkan'),
        ]);
    }

    /**
     * Cek apakah modul tertentu aktif untuk store.
     */
    public static function isEnabled(?Store $store, string $moduleName): bool
    {
        if (! $store) {
            return false;
        }

        return (bool) ($store->settings[$moduleName] ?? false);
    }

    /**
     * Ambil riwayat perubahan modul untuk store tertentu.
     */
    public static function getHistory(Store $store, ?string $moduleName = null, int $limit = 50)
    {
        $query = ModuleActivityLog::forStore($store->id)
            ->with(['user:id,name,email'])
            ->recent()
            ->limit($limit);

        if ($moduleName) {
            $query->module($moduleName);
        }

        return $query->get();
    }

    /**
     * Ambil daftar semua modul yang tersedia beserta statusnya untuk store.
     */
    public static function getAllModulesStatus(Store $store): array
    {
        $settings = $store->settings ?? [];
        $result = [];

        foreach (self::MODULES as $key => $label) {
            $result[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => (bool) ($settings[$key] ?? false),
            ];
        }

        return $result;
    }
}
