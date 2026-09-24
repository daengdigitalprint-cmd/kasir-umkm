<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AI\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    public function chat(ChatRequest $request): JsonResponse
    {
        $user = Auth::user();
        $store = $user->store;

        $conversation = $request->validated('conversation_id')
            ? AiConversation::whereKey($request->validated('conversation_id'))
                ->where('user_id', $user->id)
                ->first()
            : null;

        $aiService = new AiService($user, $store);
        $this->registerTools($aiService, $user);

        try {
            $result = $aiService->chat($request->validated('message'), $conversation);
        } catch (\Throwable $e) {
            Log::error('AI Chat Error', ['message' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan saat memproses chat. Silakan coba lagi beberapa detik.',
                'conversation_id' => $conversation?->id,
            ], 500);
        }

        if (isset($result['confirmation'])) {
            $confirmationId = (string) Str::uuid();
            Cache::put("ai_confirmations.{$confirmationId}", [
                'conversation_id' => $result['conversation_id'],
                'tool' => $result['confirmation']['tool'],
                'arguments' => $result['confirmation']['arguments'],
            ], now()->addMinutes(30));
            $result['confirmation']['id'] = $confirmationId;
        }

        if (($result['success'] ?? true) === false) {
            return response()->json($result, $result['status'] ?? 502);
        }

        return response()->json($result);
    }

    /**
     * Chat dengan respons streaming (SSE): token dikirim per potongan
     * sehingga teks muncul nyaris seketika di UI.
     */
    public function chatStream(ChatRequest $request): StreamedResponse
    {
        $user = Auth::user();
        $store = $user->store;

        $conversation = $request->validated('conversation_id')
            ? AiConversation::whereKey($request->validated('conversation_id'))
                ->where('user_id', $user->id)
                ->first()
            : null;

        $aiService = new AiService($user, $store);
        $this->registerTools($aiService, $user);

        $message = $request->validated('message');

        return response()->stream(function () use ($aiService, $conversation, $message) {
            $send = function (array $payload): void {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

                if (! app()->runningUnitTests()) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            };

            try {
                $result = $aiService->chat($message, $conversation, function (string $delta) use ($send) {
                    $send(['type' => 'delta', 'content' => $delta]);
                });

                if (isset($result['confirmation'])) {
                    $confirmationId = (string) Str::uuid();
                    Cache::put("ai_confirmations.{$confirmationId}", [
                        'conversation_id' => $result['conversation_id'],
                        'tool' => $result['confirmation']['tool'],
                        'arguments' => $result['confirmation']['arguments'],
                    ], now()->addMinutes(30));
                    $result['confirmation']['id'] = $confirmationId;
                }

                $send(['type' => 'result', 'data' => $result]);
            } catch (\Throwable $e) {
                Log::error('AI Stream Error', ['message' => $e->getMessage(), 'user_id' => $user->id]);

                $send(['type' => 'error', 'message' => 'Terjadi kesalahan saat memproses chat. Silakan coba lagi beberapa detik.']);
            }

            $send(['type' => 'done']);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['confirmation_id' => ['required', 'uuid']]);
        $confirmation = Cache::pull("ai_confirmations.{$data['confirmation_id']}");

        if (! $confirmation) {
            return response()->json(['success' => false, 'error' => 'Konfirmasi tidak ditemukan atau sudah diproses.'], 422);
        }

        $user = Auth::user();
        $conversation = AiConversation::where('id', $confirmation['conversation_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $conversation) {
            return response()->json(['success' => false, 'error' => 'Percakapan tidak ditemukan.'], 404);
        }

        $aiService = new AiService($user, $user->store);
        $this->registerTools($aiService, $user);

        try {
            return response()->json($aiService->executeConfirmedToolCall($conversation, [
                'tool' => $confirmation['tool'],
                'arguments' => $confirmation['arguments'],
            ]));
        } catch (\Throwable $e) {
            Log::error('AI Confirm Error', ['message' => $e->getMessage(), 'user_id' => $user->id]);

            return response()->json([
                'success' => false,
                'error' => 'Gagal memproses konfirmasi. Silakan coba lagi.',
            ], 500);
        }
    }

    public function reject(Request $request): JsonResponse
    {
        $data = $request->validate(['confirmation_id' => ['required', 'uuid']]);
        $confirmation = Cache::pull("ai_confirmations.{$data['confirmation_id']}");

        if ($confirmation) {
            $user = Auth::user();
            (new AiService($user, $user->store))->logConfirmationRejected($confirmation);
        }

        return response()->json(['success' => true]);
    }

    protected function registerTools(AiService $aiService, $user): void
    {
        $aiService->registerTool(new \App\Tools\Products\SearchProductTool);
        $aiService->registerTool(new \App\Tools\Stock\CheckStockTool);
        $aiService->registerTool(new \App\Tools\Stock\PredictStockRunwayTool);
        $aiService->registerTool(new \App\Tools\Reports\TopProductsTool);
        $aiService->registerTool(new \App\Tools\Reports\SalesSummaryTool);
        $aiService->registerTool(new \App\Tools\Reports\SalesInsightTool);
        $aiService->registerTool(new \App\Tools\Reports\SalesForecastTool);
        $aiService->registerTool(new \App\Tools\Customers\CustomerInsightTool);
        $aiService->registerTool(new \App\Tools\Audit\ActionLogTool);
        $aiService->registerTool(new \App\Tools\Reports\ProfitReportTool);
        $aiService->registerTool(new \App\Tools\Reports\BranchComparisonTool);
        $aiService->registerTool(new \App\Tools\Stock\RecommendRestockTool);
        $aiService->registerTool(new \App\Tools\Stock\PurchaseSuggestionTool);
        $aiService->registerTool(new \App\Tools\Reports\TransactionAnomalyTool);
        $aiService->registerTool(new \App\Tools\Products\PriceRecommendationTool);
        $aiService->registerTool(new \App\Tools\Reports\ProductProfitInsightTool);
        $aiService->registerTool(new \App\Tools\Operational\SearchCustomerTool);

        if ($user->isSuperAdmin() || $user->isAdmin() || $user->isManager()) {
            $aiService->registerTool(new \App\Tools\Products\CreateProductTool);
            $aiService->registerTool(new \App\Tools\Products\UpdateProductTool);
            $aiService->registerTool(new \App\Tools\Stock\UpdateStockTool);
            $aiService->registerTool(new \App\Tools\Stock\BulkUpdateStockTool);
            $aiService->registerTool(new \App\Tools\Purchases\CreatePurchaseOrderTool);
        }
    }

    public function conversations(): JsonResponse
    {
        $user = Auth::user();

        $conversations = AiConversation::where('user_id', $user->id)
            ->with('latestMessage')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'title' => $conv->title,
                'summary' => $conv->summary,
                'last_message_at' => $conv->updated_at->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    public function messages(AiConversation $conversation): JsonResponse
    {
        $user = Auth::user();

        if ($conversation->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $messages = $conversation->messages()
            ->where('role', '!=', 'system')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'tool_calls' => $msg->tool_calls,
                'tool_call_id' => $msg->tool_call_id,
                'feedback' => $msg->feedback,
                'timestamp' => $msg->created_at->format('H:i'),
            ]);

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
            'messages' => $messages,
        ]);
    }

    public function newConversation(): JsonResponse
    {
        $user = Auth::user();
        $store = $user->store;

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'store_id' => $store?->id,
            'title' => 'Percakapan Baru',
        ]);

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
        ]);
    }

    public function deleteConversation(AiConversation $conversation): JsonResponse
    {
        $user = Auth::user();

        if ($conversation->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Percakapan berhasil dihapus',
        ]);
    }

    public function feedback(Request $request, AiMessage $message): JsonResponse
    {
        $user = Auth::user();

        // Validasi kepemilikan pesan
        $conversation = $message->conversation;
        if ($conversation->user_id !== $user->id) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'feedback' => 'required|in:positive,negative',
        ]);

        $message->update([
            'feedback' => $request->input('feedback'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feedback berhasil disimpan',
        ]);
    }
}
