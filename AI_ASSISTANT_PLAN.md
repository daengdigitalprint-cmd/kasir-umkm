# Rencana Implementasi AI Assistant POS UMKM

## Overview
Fitur AI Assistant yang membantu user mengelola produk, stok, dan operasional toko melalui chat interface.

**Tujuan**: Mempercepat operasi harian dengan perintah natural language.

---

## Progress Tracker

| Phase | Status | Target |
|-------|--------|--------|
| Phase 1: Foundation | ✅ Selesai | - |
| Phase 2: Core Tools | ✅ Selesai | - |
| Phase 3: UI Integration | 🔄 Progress | - |
| Phase 4: Advanced Features | ⬜ Belum | - |

---

## Phase 1: Foundation

### 1.1 Database & Model
- [x] Migration: `ai_configurations` table
- [x] Migration: `ai_conversations` table
- [x] Migration: `ai_messages` table
- [x] Migration: `ai_audit_logs` table
- [x] Model: `AiConfiguration`
- [x] Model: `AiConversation`
- [x] Model: `AiMessage`
- [x] Model: `AiAuditLog`

### 1.2 AI Service Layer
- [x] Service: `AiService` (main service)
- [x] Service: `AiToolRegistry` (tool management)
- [x] Service: `AiPromptBuilder` (system prompt generator)
- [x] Interface: `AiProviderInterface` (abstraction layer)

### 1.3 AI Provider Integration (Flexible/Custom)
- [x] Provider: `OpenAiCompatibleProvider`
- [ ] Provider: `CustomEndpointProvider`
- [x] Support custom base URL (OpenAI, Claude, local LLM, dll)
- [x] Support custom model ID
- [x] Support custom API key per outlet/toko

### 1.4 Konfigurasi
- [x] Config: `config/ai.php`
- [x] Admin panel: Setting AI provider
- [x] Validasi API key & connection test

---

## Phase 2: Core Tools (Function Calling)

### 2.1 Produk Tools
- [x] `create_product` — Buat produk baru
- [x] `update_product` — Update produk
- [ ] `duplicate_product` — Duplikat produk
- [x] `search_product` — Cari produk
- [ ] `bulk_update_price` — Update harga massal

### 2.2 Stok Tools
- [x] `check_stock` — Cek stok produk
- [x] `update_stock` — Update stok (tambah/kurang)
- [ ] `get_low_stock` — Produk stok rendah
- [ ] `create_stock_alert` — Buat alert stok

### 2.3 Laporan Tools
- [x] `get_sales_summary` — Ringkasan penjualan
- [x] `get_top_products` — Produk terlaris
- [ ] `get_revenue_report` — Laporan pendapatan
- [ ] `compare_periods` — Bandingkan periode

### 2.4 Operasional Tools
- [ ] `create_discount` — Buat diskon
- [ ] `get_cashier_summary` — Ringkasan kasir
- [ ] `search_customer` — Cari pelanggan

---

## Phase 3: UI Integration

### 3.1 Chat Component
- [x] Component: `AiAssistantSidebar.vue`
- [x] Component: `AiChatMessage.vue`
- [ ] Component: `AiQuickActions.vue`
- [ ] Component: `AiConfirmationDialog.vue`

### 3.2 POS Integration
- [ ] Tambah sidebar chat di POS
- [ ] Keyboard shortcut (Ctrl+Shift+A)
- [ ] Quick actions button

### 3.3 Admin Integration
- [ ] Halaman pengaturan AI
- [ ] Test connection button
- [ ] View conversation history
- [ ] View audit logs

### 3.4 UX Features
- [x] Loading indicator
- [x] Error handling & retry
- [x] Message history
- [ ] Feedback (👍👎)

---

## Phase 4: Advanced Features

### 4.1 Proactive Features
- [ ] Auto-alert stok rendah
- [ ] Daily summary notification
- [ ] Suggestion berdasarkan pola

### 4.2 Learning & Context
- [ ] Remember frequent commands
- [ ] Context dari percakapan sebelumnya
- [ ] User preference learning

### 4.3 Multi-outlet
- [ ] Context per outlet
- [ ] Cross-outlet queries (admin only)

---

## Arsitektur Teknis

### Database Schema

```sql
-- Konfigurasi AI per outlet/toko
CREATE TABLE ai_configurations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    outlet_id BIGINT NULL,
    provider VARCHAR(50) NOT NULL, -- 'openai', 'custom', 'local'
    base_url VARCHAR(500) NOT NULL,
    model_id VARCHAR(100) NOT NULL,
    api_key TEXT NOT NULL, -- encrypted
    max_tokens INT DEFAULT 4096,
    temperature DECIMAL(3,2) DEFAULT 0.7,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Percakapan
CREATE TABLE ai_conversations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    outlet_id BIGINT NULL,
    title VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Pesan dalam percakapan
CREATE TABLE ai_messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    conversation_id BIGINT NOT NULL,
    role ENUM('user', 'assistant', 'system', 'tool') NOT NULL,
    content TEXT NULL,
    tool_calls JSON NULL,
    tool_call_id VARCHAR(100) NULL,
    tokens_used INT NULL,
    created_at TIMESTAMP
);

-- Audit log untuk semua aksi AI
CREATE TABLE ai_audit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    outlet_id BIGINT NULL,
    action VARCHAR(100) NOT NULL,
    tool_name VARCHAR(100) NULL,
    parameters JSON NULL,
    result JSON NULL,
    status ENUM('success', 'failed', 'cancelled') NOT NULL,
    created_at TIMESTAMP
);
```

### AI Provider Interface

```php
interface AiProviderInterface
{
    public function chat(array $messages, array $tools = []): AiResponse;
    public function validateConnection(): bool;
    public function getModels(): array;
}
```

### Tool Definition Format

```php
class CreateProductTool extends AiTool
{
    protected string $name = 'create_product';
    protected string $description = 'Membuat produk baru di sistem';

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nama produk'],
                'price' => ['type' => 'number', 'description' => 'Harga jual'],
                'category' => ['type' => 'string', 'description' => 'Kategori produk'],
                'stock' => ['type' => 'integer', 'description' => 'Stok awal'],
            ],
            'required' => ['name', 'price'],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        // Validasi & eksekusi
    }
}
```

### API Request Format (OpenAI Compatible)

```php
// Flexible: bisa ke OpenAI, Claude, atau custom endpoint
$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $config->api_key,
    'Content-Type' => 'application/json',
])->post($config->base_url . '/chat/completions', [
    'model' => $config->model_id,
    'messages' => $messages,
    'tools' => $tools,
    'temperature' => $config->temperature,
    'max_tokens' => $config->max_tokens,
]);
```

---

## File Structure

```
app/
├── Services/
│   └── AI/
│       ├── AiService.php
│       ├── AiToolRegistry.php
│       ├── AiPromptBuilder.php
│       └── Providers/
│           ├── AiProviderInterface.php
│           ├── OpenAiCompatibleProvider.php
│           └── CustomEndpointProvider.php
├── Tools/
│   ├── AiTool.php (abstract)
│   ├── Products/
│   │   ├── CreateProductTool.php
│   │   ├── UpdateProductTool.php
│   │   └── ...
│   ├── Stock/
│   │   ├── CheckStockTool.php
│   │   └── ...
│   └── Reports/
│       ├── SalesSummaryTool.php
│       └── ...
├── Models/
│   ├── AiConfiguration.php
│   ├── AiConversation.php
│   ├── AiMessage.php
│   └── AiAuditLog.php
├── Http/
│   ├── Controllers/
│   │   └── Ai/
│   │       ├── AiChatController.php
│   │       ├── AiConfigController.php
│   │       └── AiHistoryController.php
│   └── Requests/
│       └── Ai/
│           ├── ChatRequest.php
│           └── UpdateConfigRequest.php
└── Jobs/
    └── ProcessAiMessage.php

resources/js/
├── components/
│   └── ai/
│       ├── AiAssistantSidebar.vue
│       ├── AiChatMessage.vue
│       ├── AiQuickActions.vue
│       ├── AiConfirmationDialog.vue
│       └── AiSettings.vue
└── pages/
    └── Admin/
        └── AI/
            ├── Settings.vue
            ├── History.vue
            └── AuditLog.vue

routes/
├── web.php (tambah AI routes)
└── api.php (tambah AI API routes)

config/
└── ai.php

database/
└── migrations/
    ├── create_ai_configurations_table.php
    ├── create_ai_conversations_table.php
    ├── create_ai_messages_table.php
    └── create_ai_audit_logs_table.php
```

---

## Checklist Per File

### Backend (Laravel)
| File | Status | Notes |
|------|--------|-------|
| `config/ai.php` | ⬜ | - |
| `database/migrations/...ai_configurations.php` | ⬜ | - |
| `database/migrations/...ai_conversations.php` | ⬜ | - |
| `database/migrations/...ai_messages.php` | ⬜ | - |
| `database/migrations/...ai_audit_logs.php` | ⬜ | - |
| `app/Models/AiConfiguration.php` | ⬜ | - |
| `app/Models/AiConversation.php` | ⬜ | - |
| `app/Models/AiMessage.php` | ⬜ | - |
| `app/Models/AiAuditLog.php` | ⬜ | - |
| `app/Services/AI/AiProviderInterface.php` | ⬜ | - |
| `app/Services/AI/Providers/OpenAiCompatibleProvider.php` | ⬜ | - |
| `app/Services/AI/Providers/CustomEndpointProvider.php` | ⬜ | - |
| `app/Services/AI/AiService.php` | ⬜ | - |
| `app/Services/AI/AiToolRegistry.php` | ⬜ | - |
| `app/Services/AI/AiPromptBuilder.php` | ⬜ | - |
| `app/Tools/AiTool.php` | ⬜ | - |
| `app/Tools/Products/CreateProductTool.php` | ⬜ | - |
| `app/Tools/Products/UpdateProductTool.php` | ⬜ | - |
| `app/Tools/Products/DuplicateProductTool.php` | ⬜ | - |
| `app/Tools/Products/SearchProductTool.php` | ⬜ | - |
| `app/Tools/Products/BulkUpdatePriceTool.php` | ⬜ | - |
| `app/Tools/Stock/CheckStockTool.php` | ⬜ | - |
| `app/Tools/Stock/UpdateStockTool.php` | ⬜ | - |
| `app/Tools/Stock/GetLowStockTool.php` | ⬜ | - |
| `app/Tools/Reports/SalesSummaryTool.php` | ⬜ | - |
| `app/Tools/Reports/TopProductsTool.php` | ⬜ | - |
| `app/Tools/Reports/RevenueReportTool.php` | ⬜ | - |
| `app/Tools/Operational/CreateDiscountTool.php` | ⬜ | - |
| `app/Tools/Operational/SearchCustomerTool.php` | ⬜ | - |
| `app/Http/Controllers/AI/AiChatController.php` | ⬜ | - |
| `app/Http/Controllers/AI/AiConfigController.php` | ⬜ | - |
| `app/Http/Controllers/AI/AiHistoryController.php` | ⬜ | - |
| `app/Http/Requests/AI/ChatRequest.php` | ⬜ | - |
| `app/Http/Requests/AI/UpdateConfigRequest.php` | ⬜ | - |
| `app/Jobs/ProcessAiMessage.php` | ⬜ | - |

### Frontend (Vue)
| File | Status | Notes |
|------|--------|-------|
| `resources/js/components/ai/AiAssistantSidebar.vue` | ⬜ | - |
| `resources/js/components/ai/AiChatMessage.vue` | ⬜ | - |
| `resources/js/components/ai/AiQuickActions.vue` | ⬜ | - |
| `resources/js/components/ai/AiConfirmationDialog.vue` | ⬜ | - |
| `resources/js/components/ai/AiSettings.vue` | ⬜ | - |
| `resources/js/pages/Admin/AI/Settings.vue` | ⬜ | - |
| `resources/js/pages/Admin/AI/History.vue` | ⬜ | - |
| `resources/js/pages/Admin/AI/AuditLog.vue` | ⬜ | - |

### Routes & Tests
| File | Status | Notes |
|------|--------|-------|
| `routes/web.php` (AI routes) | ⬜ | - |
| `routes/api.php` (AI API) | ⬜ | - |
| `tests/Feature/AI/AiChatTest.php` | ⬜ | - |
| `tests/Feature/AI/AiConfigTest.php` | ⬜ | - |
| `tests/Unit/Services/AiServiceTest.php` | ⬜ | - |
| `tests/Unit/Tools/CreateProductToolTest.php` | ⬜ | - |

---

## Catatan Implementasi

### Fleksibilitas AI Provider
User bisa konfigurasi:
- **Base URL**: Bisa OpenAI, Claude API, atau self-hosted LLM
- **Model ID**: Bisa ganti model sesuai kebutuhan
- **API Key**: Per outlet/toko (multi-tenant)

### Security
- API key di-encrypt di database
- Rate limiting per user
- Audit log untuk semua aksi
- Konfirmasi sebelum eksekusi (ubah data)

### Performance
- Gunakan queue untuk proses AI
- Cache response untuk query umum
- Streaming response untuk UX lebih baik

---

*Last updated: 2026-09-21*