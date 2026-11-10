<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\Store;
use App\Models\User;
use App\Services\AI\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAiMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $message,
        public readonly int $userId,
        public readonly ?int $storeId = null,
        public readonly ?int $conversationId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): array
    {
        $user = User::findOrFail($this->userId);
        $store = $this->storeId ? Store::find($this->storeId) : null;
        $conversation = $this->conversationId ? AiConversation::find($this->conversationId) : null;

        $aiService = new AiService($user, $store);

        // Register tools yang tersedia
        $this->registerTools($aiService);

        return $aiService->chat($this->message, $conversation);
    }

    protected function registerTools(AiService $aiService): void
    {
        // Register tools berdasarkan permission user
        $user = User::findOrFail($this->userId);

        // Products tools
        if ($user->can('manage_products')) {
            $aiService->registerTool(new \App\Tools\Products\CreateProductTool);
            $aiService->registerTool(new \App\Tools\Products\UpdateProductTool);
            $aiService->registerTool(new \App\Tools\Products\SearchProductTool);
            $aiService->registerTool(new \App\Tools\Products\PriceRecommendationTool);
        }

        // Stock tools
        if ($user->can('manage_stock')) {
            $aiService->registerTool(new \App\Tools\Stock\CheckStockTool);
            $aiService->registerTool(new \App\Tools\Stock\PredictStockRunwayTool);
            $aiService->registerTool(new \App\Tools\Stock\UpdateStockTool);
            $aiService->registerTool(new \App\Tools\Stock\RecommendRestockTool);
            $aiService->registerTool(new \App\Tools\Stock\PurchaseSuggestionTool);
            $aiService->registerTool(new \App\Tools\Reports\ProductProfitInsightTool);
            $aiService->registerTool(new \App\Tools\Purchases\CreatePurchaseOrderTool);
        }

        // Reports tools
        if ($user->can('view_reports')) {
            $aiService->registerTool(new \App\Tools\Reports\SalesSummaryTool);
            $aiService->registerTool(new \App\Tools\Reports\SalesInsightTool);
            $aiService->registerTool(new \App\Tools\Customers\CustomerInsightTool);
            $aiService->registerTool(new \App\Tools\Operational\SearchCustomerTool);
            $aiService->registerTool(new \App\Tools\Audit\ActionLogTool);
            $aiService->registerTool(new \App\Tools\Reports\TopProductsTool);
            $aiService->registerTool(new \App\Tools\Reports\ProfitReportTool);
            $aiService->registerTool(new \App\Tools\Reports\BranchComparisonTool);
            $aiService->registerTool(new \App\Tools\Reports\TransactionAnomalyTool);
            $aiService->registerTool(new \App\Tools\Reports\BarberCommissionTool);
        }
    }
}
