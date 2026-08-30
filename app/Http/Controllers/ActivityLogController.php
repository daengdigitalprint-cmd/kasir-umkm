<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Validasi hak akses
        if (! $user->canAccessActivityLogs()) {
            abort(403, 'Akses ditolak. Hanya admin dan owner yang dapat melihat log aktivitas.');
        }

        // Dapatkan semua outlet yang bisa diakses user
        $accessibleStoreIds = $user->isSuperAdmin()
            ? Store::pluck('id')->all()
            : $user->stores()->pluck('stores.id')->all();

        if (empty($accessibleStoreIds)) {
            abort(403, 'Tidak ada outlet yang dapat diakses.');
        }

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = ActivityLog::query()
            ->whereIn('store_id', $accessibleStoreIds)
            ->with(['store:id,name', 'user:id,name,email'])
            ->orderByDesc('created_at');

        // Filter outlet
        if (! empty($validated['store_id']) && in_array($validated['store_id'], $accessibleStoreIds)) {
            $query->where('store_id', $validated['store_id']);
        }

        // Filter aksi
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        // Filter user pelaku
        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        // Filter rentang tanggal
        if (! empty($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate('created_at', '<=', $validated['end_date']);
        }

        // Pencarian deskripsi
        if (! empty($validated['search'])) {
            $query->where('description', 'like', '%'.$validated['search'].'%');
        }

        $logs = $query->paginate(50)->withQueryString();

        // Daftar aksi unik untuk filter dropdown
        $actions = ActivityLog::query()
            ->whereIn('store_id', $accessibleStoreIds)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        // Daftar user untuk filter
        $users = \App\Models\User::query()
            ->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $accessibleStoreIds))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('ActivityLogs/Index', [
            'logs' => $logs,
            'actions' => $actions,
            'users' => $users,
            'filters' => [
                'store_id' => $validated['store_id'] ?? null,
                'action' => $validated['action'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
        ]);
    }
}
