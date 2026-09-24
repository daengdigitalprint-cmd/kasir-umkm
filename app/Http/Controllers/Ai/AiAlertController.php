<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiAlert;
use Illuminate\Http\JsonResponse;
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
}
