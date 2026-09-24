<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiAuditLog;
use App\Models\AiConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiHistoryController extends Controller
{
    public function conversations(Request $request): Response
    {
        $this->authorize('view_ai_history');

        $user = $request->user();

        $conversations = AiConversation::where('user_id', $user->id)
            ->with('latestMessage')
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return Inertia::render('Admin/AI/History', [
            'conversations' => $conversations,
        ]);
    }

    public function auditLogs(Request $request): Response
    {
        $this->authorize('view_ai_audit_logs');

        $query = AiAuditLog::with(['user', 'store']);

        // Filter berdasarkan status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter berdasarkan tool
        if ($request->has('tool_name')) {
            $query->where('tool_name', $request->tool_name);
        }

        // Filter berdasarkan user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter berdasarkan tanggal
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('Admin/AI/AuditLog', [
            'logs' => $logs,
            'filters' => $request->only(['status', 'tool_name', 'user_id', 'date_from', 'date_to']),
        ]);
    }

    public function conversationDetail(AiConversation $conversation): Response
    {
        $this->authorize('view_ai_history');

        $user = request()->user();

        // Pastikan user hanya bisa lihat conversation sendiri (kecuali admin)
        if ($conversation->user_id !== $user->id && ! $user->can('view_all_ai_conversations')) {
            abort(403);
        }

        $conversation->load('messages');

        return Inertia::render('Admin/AI/ConversationDetail', [
            'conversation' => $conversation,
        ]);
    }

    public function getStats(): JsonResponse
    {
        $this->authorize('view_ai_audit_logs');

        $stats = [
            'total_conversations' => AiConversation::count(),
            'total_messages' => \App\Models\AiMessage::count(),
            'total_tool_executions' => AiAuditLog::count(),
            'successful_executions' => AiAuditLog::successful()->count(),
            'failed_executions' => AiAuditLog::failed()->count(),
            'most_used_tools' => AiAuditLog::select('tool_name', \DB::raw('COUNT(*) as count'))
                ->groupBy('tool_name')
                ->orderByDesc('count')
                ->limit(5)
                ->pluck('count', 'tool_name'),
            'active_users' => AiConversation::distinct('user_id')->count(),
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }
}
