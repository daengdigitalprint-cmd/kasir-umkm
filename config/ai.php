<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Provider default yang digunakan jika tidak ada konfigurasi spesifik
    | per outlet. Bisa di-override dari database (ai_configurations).
    |
    */

    'default_provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk setiap provider AI yang didukung.
    | User bisa menambah custom provider dari admin panel.
    |
    */

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'key' => env('AI_OPENAI_API_KEY'),
            'url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model_id' => env('AI_OPENAI_MODEL', 'gpt-4o'),
            'api_key' => env('AI_OPENAI_API_KEY'),
            'max_tokens' => (int) env('AI_OPENAI_MAX_TOKENS', 4096),
            'temperature' => (float) env('AI_OPENAI_TEMPERATURE', 0.7),
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('AI_ANTHROPIC_API_KEY'),
            'url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model_id' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
            'api_key' => env('AI_ANTHROPIC_API_KEY'),
            'max_tokens' => (int) env('AI_ANTHROPIC_MAX_TOKENS', 4096),
            'temperature' => (float) env('AI_ANTHROPIC_TEMPERATURE', 0.7),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (detik)
    |--------------------------------------------------------------------------
    |
    | Durasi tunggu maksimum untuk respons provider AI (OpenAI/Gemini/dll).
    | Jika melebihi batas ini, aplikasi membalas error ramah alih-alih
    | membiarkan HTTP membeku sampai timeout Nginx/PHP-FPM.
    |
    */

    'timeout' => (int) env('AI_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Batasan rate limit per user untuk mencegah abuse.
    |
    */

    'rate_limit' => [
        'max_requests' => (int) env('AI_RATE_LIMIT_MAX', 100),
        'per_minutes' => (int) env('AI_RATE_LIMIT_PERIOD', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    |
    | Prompt dasar yang dikirim ke AI untuk memberikan konteks aplikasi.
    | Bisa di-override per outlet.
    |
    */

    'system_prompt' => env('AI_SYSTEM_PROMPT', 'Kamu adalah Macca, asisten AI untuk aplikasi POS UMKM. Jawab singkat, langsung, dan praktis. Gunakan tabel Markdown untuk data numerik.

ATURAN TOOL:
- Jika user sudah menyebut NAMA PRODUK + HARGA (minimal), langsung eksekusi tool terkait.
- Jangan minta formulir atau konfirmasi. Jangan balas dengan prompt "isi informasi".
- Ekstrak semua info dari pesan user. Mapping: harga jual=price, HPP/harga modal=cost, stok=stock, kategori=category, satuan=unit.
- Jika ada kalimat seperti "Tambah produk X, harga jual ..., modal ..., kategori ..., stok ..., satuan ...", langsung panggil create_product.
- Jika user meminta beli/beliin/pesan/restock barang (ada "dari supplier" atau "bayar cash/kredit"), pakai create_purchase_order, bukan create_product.
- Jangan panggil create_product tanpa field price.
- Setelah tool dijalankan, cukup lapor hasil tool secara ringkas.

Gunakan bahasa Indonesia ramah.'),

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Aktifkan/nonaktifkan audit log untuk semua aksi AI.
    |
    */

    'audit_log_enabled' => (bool) env('AI_AUDIT_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Confirmation Required
    |--------------------------------------------------------------------------
    |
    | Jika true, semua aksi yang mengubah data harus dikonfirmasi user.
    |
    */

    'confirmation_required' => (bool) env('AI_CONFIRMATION_REQUIRED', true),

    /*
    |--------------------------------------------------------------------------
    | Confirmation Tools
    |--------------------------------------------------------------------------
    |
    | Tool yang selalu memerlukan persetujuan user sebelum dieksekusi.
    |
    */

    'confirmation_tools' => ['create_product', 'update_product', 'update_stock', 'update_stock_bulk', 'create_purchase_order'],

];
