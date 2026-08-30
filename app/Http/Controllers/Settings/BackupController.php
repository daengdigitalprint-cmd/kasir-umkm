<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\BackupHistory;
use App\Services\StoreBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function __construct(
        private StoreBackupService $backupService
    ) {}

    /**
     * Halaman pengaturan backup (dari SettingsLayout).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $store = $user->store;

        $scheduledBackups = [];
        if ($store && Storage::exists("backups/store-{$store->id}")) {
            $files = Storage::files("backups/store-{$store->id}");
            $scheduledBackups = collect($files)
                ->map(fn ($path) => [
                    'filename' => basename($path),
                    'size' => Storage::size($path),
                    'created_at' => Storage::lastModified($path),
                ])
                ->sortByDesc('created_at')
                ->take(10)
                ->values()
                ->all();
        }

        return Inertia::render('settings/Backup', [
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
            ] : null,
            'scheduled_backups' => $scheduledBackups,
        ]);
    }

    /**
     * Daftar riwayat pencadangan (JSON API untuk BackupTab).
     */
    public function history(Request $request): JsonResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store tidak ditemukan.'], 404);
        }

        $query = BackupHistory::where('store_id', $store->id)
            ->with('user:id,name')
            ->orderByDesc('created_at');

        // Filter search
        if ($search = $request->input('search')) {
            $query->where('filename', 'like', "%{$search}%");
        }

        // Filter status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter trigger type
        if ($triggerType = $request->input('trigger_type')) {
            $query->where('trigger_type', $triggerType);
        }

        $histories = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $histories->items(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
                'per_page' => $histories->perPage(),
                'total' => $histories->total(),
            ],
        ]);
    }

    /**
     * Generate backup manual — simpan file + catat history.
     */
    public function createBackup(Request $request): JsonResponse
    {
        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store tidak ditemukan.'], 404);
        }

        try {
            $history = $this->backupService->saveAndRecord(
                $store,
                triggerType: 'manual',
                userId: $user->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Backup berhasil dibuat.',
                'data' => [
                    'id' => $history->id,
                    'filename' => $history->filename,
                    'file_size' => $history->file_size,
                    'duration_ms' => $history->duration_ms,
                    'records_count' => $history->records_count,
                    'records_summary' => $history->records_summary,
                    'created_at' => $history->created_at->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat backup: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download backup — stream file via response.
     */
    public function download(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store tidak ditemukan.'], 404);
        }

        // Jika ada backup_id, download dari history
        if ($backupId = $request->input('backup_id')) {
            $history = BackupHistory::where('id', $backupId)
                ->where('store_id', $store->id)
                ->first();

            if (! $history || ! $history->isCompleted()) {
                return response()->json(['success' => false, 'message' => 'Backup tidak ditemukan.'], 404);
            }

            if (! Storage::exists($history->filepath)) {
                return response()->json(['success' => false, 'message' => 'File backup tidak ditemukan di server.'], 404);
            }

            return Storage::download($history->filepath, $history->filename);
        }

        // Backup on-the-fly (tanpa simpan)
        try {
            $backup = $this->backupService->generateBackup($store);

            $filename = sprintf(
                'backup-%s-%s.json',
                $store->slug,
                now()->format('Y-m-d_His')
            );

            return response()->json($backup)
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat backup: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore data dari file backup.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'backup_id' => 'required|integer|exists:backup_histories,id',
        ]);

        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store tidak ditemukan.'], 404);
        }

        $history = BackupHistory::where('id', $request->input('backup_id'))
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->first();

        if (! $history) {
            return response()->json(['success' => false, 'message' => 'Backup tidak ditemukan atau belum selesai.'], 404);
        }

        if (! Storage::exists($history->filepath)) {
            return response()->json(['success' => false, 'message' => 'File backup tidak ditemukan.'], 404);
        }

        try {
            $log = $this->backupService->restoreFromBackup($store, $history->filepath);

            return response()->json([
                'success' => true,
                'message' => 'Pemulihan data berhasil.',
                'log' => $log,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memulihkan data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download backup terjadwal dari storage.
     */
    public function downloadScheduled(Request $request, string $filename): mixed
    {
        $store = $request->user()->store;

        if (! $store) {
            abort(404, 'Store tidak ditemukan.');
        }

        $path = "backups/store-{$store->id}/{$filename}";

        if (! Storage::exists($path)) {
            abort(404, 'File backup tidak ditemukan.');
        }

        return Storage::download($path, $filename);
    }

    /**
     * Hapus backup dari history & storage.
     */
    public function deleteBackup(Request $request, int $id): JsonResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Store tidak ditemukan.'], 404);
        }

        $history = BackupHistory::where('id', $id)
            ->where('store_id', $store->id)
            ->first();

        if (! $history) {
            return response()->json(['success' => false, 'message' => 'Backup tidak ditemukan.'], 404);
        }

        if (Storage::exists($history->filepath)) {
            Storage::delete($history->filepath);
        }

        $history->delete();

        return response()->json([
            'success' => true,
            'message' => 'Backup berhasil dihapus.',
        ]);
    }
}
