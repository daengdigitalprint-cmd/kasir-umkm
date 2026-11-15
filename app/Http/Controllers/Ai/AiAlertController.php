<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiAlert;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiAlertController extends Controller
{
    /**
     * Daftar peringatan terbaru milik user login (untuk bell & dropdown).
     */
    public function index(Request $request): JsonResponse
    {
        $alerts = AiAlert::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AiAlert $alert): array => [
                'id' => $alert->id,
                'type' => $alert->type,
                'title' => $alert->title,
                'message' => $alert->message,
                'is_read' => $alert->is_read,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['alerts' => $alerts]);
    }

    /**
     * Tandai satu peringatan sudah dibaca.
     */
    public function markRead(Request $request, AiAlert $alert): JsonResponse
    {
        abort_unless((int) $alert->user_id === (int) $request->user()->id, 403);

        if (! $alert->is_read) {
            $alert->update(['is_read' => true]);
        }

        return response()->json(['alert' => ['id' => $alert->id, 'is_read' => true]]);
    }

    /**
     * Klik peringatan: tandai dibaca lalu buka halaman detailnya. Bila peringatan
     * milik outlet lain, toko aktif dipindah dulu agar halaman menampilkan data
     * outlet yang benar.
     */
    public function open(Request $request, AiAlert $alert): RedirectResponse
    {
        $user = $request->user();
        abort_unless((int) $alert->user_id === (int) $user->id, 403);

        if (! $alert->is_read) {
            $alert->update(['is_read' => true]);
        }

        $redirect = redirect()->to($alert->detailUrl());

        if ($alert->store_id === null || (int) $alert->store_id === (int) $user->store_id) {
            return $redirect;
        }

        $store = Store::query()->find($alert->store_id);
        if (! $store || ! $store->is_active) {
            return back()->with('error', 'Toko pada peringatan ini sudah tidak aktif.');
        }

        if ($user->isSuperAdmin()) {
            $user->update(['store_id' => $store->id]);

            return $redirect->with('success', 'Toko aktif: '.$store->name);
        }

        $membership = $user->stores()->where('stores.id', $store->id)->first();
        if (! $membership) {
            return back()->with('error', 'Anda tidak lagi memiliki akses ke toko pada peringatan ini.');
        }

        $user->update([
            'store_id' => $store->id,
            'role' => $membership->pivot->role ?? $user->role,
        ]);

        return $redirect->with('success', 'Toko aktif: '.$store->name);
    }
}
