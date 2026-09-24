<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Organization;
use App\Services\ModuleService;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\BusinessTypePresets;
use App\Support\StoreDefaultProductCategories;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreSettingsController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'alert_whatsapp_numbers' => 'nullable|string|max:500',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'business_type' => 'required|string|in:'.implode(',', array_keys(BusinessTypePresets::TYPES)),
            'receipt_header_name' => 'nullable|string|max:255',
            'receipt_subtitle' => 'nullable|string|max:255',
            'receipt_footer' => 'nullable|string|max:500',
            'receipt_footer_line2' => 'nullable|string|max:255',
            'receipt_logo' => 'nullable|file|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'remove_receipt_logo' => 'sometimes|boolean',
            'receipt_show_address' => 'sometimes|boolean',
            'receipt_show_phone' => 'sometimes|boolean',
            'receipt_show_logo' => 'sometimes|boolean',
            'receipt_paper_width' => 'nullable|string|in:58,80',
            'batch_tracking_enabled' => 'sometimes|boolean',
            'batch_expiry_warn_days' => 'sometimes|integer|min:1|max:365',
            'batch_expiry_email_enabled' => 'sometimes|boolean',
            'default_service_charge_percent' => 'nullable|numeric|min:0|max:100',
            'organization_name' => 'nullable|string|max:255',
            'organization_attach_mode' => 'nullable|string|in:new,join',
            'attach_organization_name' => 'nullable|string|max:255',
            'attach_organization_id' => 'nullable|integer|exists:organizations,id',
            'pos_show_barcode' => 'sometimes|boolean',
            'recipes_enabled' => 'sometimes|boolean',
            'productions_enabled' => 'sometimes|boolean',
            'kitchen_queue_enabled' => 'sometimes|boolean',
            'fnb_order_types_enabled' => 'sometimes|boolean',
            'fnb_split_bill_enabled' => 'sometimes|boolean',
            'open_bill_enabled' => 'sometimes|boolean',
            'direct_stock_edit' => 'sometimes|boolean',
            'warehouse_enabled' => 'sometimes|boolean',
            'is_allow_local_purchase' => 'sometimes|boolean',
            'online_sales_enabled' => 'sometimes|boolean',
            'online_commission_gofood' => 'sometimes|numeric|min:0|max:100',
            'online_commission_grabfood' => 'sometimes|numeric|min:0|max:100',
            'online_commission_shopeefood' => 'sometimes|numeric|min:0|max:100',
            'queue_module_enabled' => 'sometimes|boolean',
            'wholesale_enabled' => 'sometimes|boolean',
            'attendance_enabled' => 'sometimes|boolean',
            'scan_meja_enabled' => 'sometimes|boolean',
            'cup_label_enabled' => 'sometimes|boolean',
            'accounting_enabled' => 'sometimes|boolean',
            'max_backdate_days' => 'sometimes|integer|min:0|max:30',
            'receivables_enabled' => 'sometimes|boolean',
            'scan_meja_payment_methods' => 'sometimes|array',
            'scan_meja_payment_methods.*.type' => 'required|string|in:cash,qris,bank_transfer',
            'scan_meja_payment_methods.*.label' => 'required|string|max:100',
            'scan_meja_payment_methods.*.active' => 'sometimes|boolean',
            'scan_meja_payment_methods.*.instructions' => 'nullable|string|max:500',
            'scan_meja_payment_methods.*.bank_name' => 'nullable|string|max:100',
            'scan_meja_payment_methods.*.account_number' => 'nullable|string|max:50',
            'scan_meja_payment_methods.*.account_name' => 'nullable|string|max:255',
            'qris_image' => 'nullable|file|image|mimes:jpeg,jpg,png,webp|max:2048',
            'cashier_login_mode' => 'sometimes|string|in:email,pin',
        ]);

        $settings = $store->settings ?? [];
        if (array_key_exists('pos_show_barcode', $validated)) {
            $settings['pos_show_barcode'] = (bool) $validated['pos_show_barcode'];
            unset($validated['pos_show_barcode']);
        }

        // Fitur resep & produksi: default berdasarkan business_type
        $featureDefaults = self::featureDefaultsForType($validated['business_type'] ?? $store->business_type);
        if (array_key_exists('recipes_enabled', $validated)) {
            $settings['recipes_enabled'] = (bool) $validated['recipes_enabled'];
            unset($validated['recipes_enabled']);
        } elseif (! isset($settings['recipes_enabled'])) {
            $settings['recipes_enabled'] = $featureDefaults['recipes_enabled'];
        }
        if (array_key_exists('productions_enabled', $validated)) {
            $settings['productions_enabled'] = (bool) $validated['productions_enabled'];
            unset($validated['productions_enabled']);
        } elseif (! isset($settings['productions_enabled'])) {
            $settings['productions_enabled'] = $featureDefaults['productions_enabled'];
        }
        // Antrian dapur: default off semua tipe bisnis
        if (array_key_exists('kitchen_queue_enabled', $validated)) {
            $settings['kitchen_queue_enabled'] = (bool) $validated['kitchen_queue_enabled'];
            unset($validated['kitchen_queue_enabled']);
        } elseif (! isset($settings['kitchen_queue_enabled'])) {
            $settings['kitchen_queue_enabled'] = false;
        }

        // F&B: tipe pesanan (dine_in/takeaway/delivery) — default on untuk fnb
        if (array_key_exists('fnb_order_types_enabled', $validated)) {
            $settings['fnb_order_types_enabled'] = (bool) $validated['fnb_order_types_enabled'];
            unset($validated['fnb_order_types_enabled']);
        } elseif (! isset($settings['fnb_order_types_enabled'])) {
            $settings['fnb_order_types_enabled'] = true;
        }

        // F&B: bagi tagihan (split bill) — default on untuk fnb
        if (array_key_exists('fnb_split_bill_enabled', $validated)) {
            $settings['fnb_split_bill_enabled'] = (bool) $validated['fnb_split_bill_enabled'];
            unset($validated['fnb_split_bill_enabled']);
        } elseif (! isset($settings['fnb_split_bill_enabled'])) {
            $settings['fnb_split_bill_enabled'] = true;
        }

        // Modul Open Bill (pesanan belum bayar): default off, bisa diaktifkan semua jenis usaha
        if (array_key_exists('open_bill_enabled', $validated)) {
            $settings['open_bill_enabled'] = (bool) $validated['open_bill_enabled'];
            unset($validated['open_bill_enabled']);
        } elseif (! isset($settings['open_bill_enabled'])) {
            $settings['open_bill_enabled'] = false;
        }

        // Koreksi stok: direct_stock_edit — hanya GrowUp yang boleh mengubah.
        if (StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            if (array_key_exists('direct_stock_edit', $validated)) {
                $settings['stock_adjustment'] = array_merge(
                    $settings['stock_adjustment'] ?? [],
                    ['direct_stock_edit' => (bool) $validated['direct_stock_edit']]
                );
                unset($validated['direct_stock_edit']);
            }
        } else {
            unset($validated['direct_stock_edit']);
        }

        // Sistem gudang: jika diaktifkan, pembelian outlet dialihkan ke Gudang Pusat.
        if (array_key_exists('warehouse_enabled', $validated)) {
            $enabled = (bool) $validated['warehouse_enabled'];
            if ($enabled && ! StoreLocationType::isCentralWarehouse($store)) {
                $hasWarehouse = $store->organization_id !== null
                    && StoreLocationType::centralWarehouseFor((int) $store->organization_id) !== null;
                if (! $hasWarehouse) {
                    return back()->withErrors([
                        'warehouse_enabled' => 'Aktifkan sistem gudang memerlukan Gudang Pusat. Buat atau daftarkan Gudang Pusat di pengaturan grup usaha terlebih dahulu.',
                    ])->withInput();
                }
            }
            $settings['warehouse_enabled'] = $enabled;
            unset($validated['warehouse_enabled']);
        }

        // Pembelian lokal outlet: izinkan outlet membeli persediaan sendiri (uang dari kas kasir)
        // meskipun sistem gudang aktif. Hanya bermakna untuk lokasi non-Gudang Pusat.
        if (array_key_exists('is_allow_local_purchase', $validated)) {
            $settings['is_allow_local_purchase'] = (bool) $validated['is_allow_local_purchase'];
            unset($validated['is_allow_local_purchase']);
        }

        // Penjualan online (aggregator F&B): GoFood, GrabFood, ShopeeFood.
        // Saat nonaktif, channel aggregator tidak muncul di POS & ditolak di backend.
        if (array_key_exists('online_sales_enabled', $validated)) {
            $settings['online_sales_enabled'] = (bool) $validated['online_sales_enabled'];
            unset($validated['online_sales_enabled']);
        }

        // Persentase komisi default per aggregator (dapat diatur di halaman pengaturan).
        foreach (['online_commission_gofood', 'online_commission_grabfood', 'online_commission_shopeefood'] as $field) {
            if (array_key_exists($field, $validated)) {
                $settings[$field] = (float) $validated[$field];
                unset($validated[$field]);
            }
        }

        // Modul antrian: default off semua tipe bisnis
        if (array_key_exists('queue_module_enabled', $validated)) {
            $settings['queue_module_enabled'] = (bool) $validated['queue_module_enabled'];
            unset($validated['queue_module_enabled']);
        } elseif (! isset($settings['queue_module_enabled'])) {
            $settings['queue_module_enabled'] = false;
        }

        // Modul cetak label cup / stiker pesanan: default off (atau default on untuk fnb)
        if (array_key_exists('cup_label_enabled', $validated)) {
            $settings['cup_label_enabled'] = (bool) $validated['cup_label_enabled'];
            unset($validated['cup_label_enabled']);
        } elseif (! isset($settings['cup_label_enabled'])) {
            $settings['cup_label_enabled'] = false;
        }

        // Modul harga grosir: default off semua tipe bisnis
        if (array_key_exists('wholesale_enabled', $validated)) {
            $settings['wholesale_enabled'] = (bool) $validated['wholesale_enabled'];
            unset($validated['wholesale_enabled']);
        } elseif (! isset($settings['wholesale_enabled'])) {
            $settings['wholesale_enabled'] = false;
        }

        // Modul Scan Meja (Menu Digital): default off semua tipe bisnis
        if (array_key_exists('scan_meja_enabled', $validated)) {
            $settings['scan_meja_enabled'] = (bool) $validated['scan_meja_enabled'];
            unset($validated['scan_meja_enabled']);
        } elseif (! isset($settings['scan_meja_enabled'])) {
            $settings['scan_meja_enabled'] = false;
        }

        // Modul Akuntansi (Jurnal): default off semua tipe bisnis.
        // Opsi B migrasi: toko yang sudah punya histori jurnal diset true oleh migrasi.
        if (array_key_exists('accounting_enabled', $validated)) {
            $newValue = (bool) $validated['accounting_enabled'];
            $oldValue = (bool) ($settings['accounting_enabled'] ?? false);
            $settings['accounting_enabled'] = $newValue;
            unset($validated['accounting_enabled']);

            if ($newValue && ! $oldValue) {
                self::prepareAccountingForActivation($store);
            }
        }

        // Limit Backdate (Maksimal input mundur untuk transaksi dan jurnal)
        if (array_key_exists('max_backdate_days', $validated)) {
            $settings['max_backdate_days'] = (int) $validated['max_backdate_days'];
            unset($validated['max_backdate_days']);
        } elseif (! isset($settings['max_backdate_days'])) {
            $settings['max_backdate_days'] = 7;
        }

        // Modul Hutang Piutang (Penjualan Kredit): default aktif (backward compatible)
        if (array_key_exists('receivables_enabled', $validated)) {
            $settings['receivables_enabled'] = (bool) $validated['receivables_enabled'];
            unset($validated['receivables_enabled']);
        } elseif (! isset($settings['receivables_enabled'])) {
            $settings['receivables_enabled'] = true;
        }

        // Metode Pembayaran Scan Meja
        if (array_key_exists('scan_meja_payment_methods', $validated)) {
            $methods = $validated['scan_meja_payment_methods'];
            $existingMethods = $settings['scan_meja_payment_methods'] ?? [];

            // Upload gambar QRIS baru jika ada
            $newQrisPath = null;
            if ($request->hasFile('qris_image')) {
                foreach ($existingMethods as $old) {
                    if (($old['type'] ?? '') === 'qris' && ! empty($old['image'] ?? null)) {
                        Storage::disk('public')->delete($old['image']);
                    }
                }
                $newQrisPath = $request->file('qris_image')->store('payment-methods/'.$store->id, 'public');
            }

            foreach ($methods as &$method) {
                if (($method['type'] ?? '') !== 'qris') {
                    continue;
                }
                // Pakai gambar baru jika diunggah, selain itu pertahankan yang lama
                $existing = collect($existingMethods)->firstWhere('type', 'qris');
                $method['image'] = $newQrisPath
                    ?? ($existing['image'] ?? null);
            }
            unset($method);

            // Normalisasi nilai boolean
            $methods = array_map(fn ($m) => array_merge($m, [
                'active' => (bool) ($m['active'] ?? true),
            ]), $methods);

            $settings['scan_meja_payment_methods'] = $methods;
            unset($validated['scan_meja_payment_methods']);
        } elseif (! isset($settings['scan_meja_payment_methods'])) {
            $settings['scan_meja_payment_methods'] = [
                ['type' => 'cash', 'label' => 'Tunai', 'active' => true, 'instructions' => 'Bayar langsung di kasir'],
                ['type' => 'qris', 'label' => 'QRIS', 'active' => true, 'image' => null],
                ['type' => 'bank_transfer', 'label' => 'Transfer Bank', 'active' => false, 'bank_name' => '', 'account_number' => '', 'account_name' => ''],
            ];
        }

        // Modul absensi: toggle aktif/nonaktif disimpan di stores.settings['attendance']['enabled'].
        if (array_key_exists('attendance_enabled', $validated)) {
            $settings['attendance'] = array_merge(
                $settings['attendance'] ?? [],
                ['enabled' => (bool) $validated['attendance_enabled']],
            );
            unset($validated['attendance_enabled']);
        }

        if (array_key_exists('website', $validated)) {
            $settings['website'] = $validated['website'] ?: null;
            unset($validated['website']);
        }

        if (array_key_exists('alert_whatsapp_numbers', $validated)) {
            $normalized = self::normalizeWhatsappNumbers((string) $validated['alert_whatsapp_numbers']);
            $settings['alert_whatsapp_numbers'] = $normalized;
            // sinkronkan ke kolom stores.phone jika kosong (untuk kompatibilitas POS/struk)
            if ($normalized !== '' && empty($validated['phone'] ?? $store->phone)) {
                $first = explode(',', $normalized)[0];
                $validated['phone'] = trim($first);
            }
            unset($validated['alert_whatsapp_numbers']);
        }

        $receiptKeys = [
            'receipt_header_name',
            'receipt_subtitle',
            'receipt_footer',
            'receipt_footer_line2',
            'receipt_show_address',
            'receipt_show_phone',
            'receipt_show_logo',
            'receipt_paper_width',
        ];
        foreach ($receiptKeys as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            $val = $validated[$key];
            if (in_array($key, ['receipt_show_address', 'receipt_show_phone', 'receipt_show_logo'], true)) {
                $settings[$key] = (bool) $val;
            } else {
                $settings[$key] = $val;
            }
            unset($validated[$key]);
        }

        if (array_key_exists('batch_tracking_enabled', $validated)) {
            $settings['batch_tracking_enabled'] = (bool) $validated['batch_tracking_enabled'];
            unset($validated['batch_tracking_enabled']);
        }

        if (array_key_exists('batch_expiry_warn_days', $validated)) {
            $settings['batch_expiry_warn_days'] = max(1, min(365, (int) $validated['batch_expiry_warn_days']));
            unset($validated['batch_expiry_warn_days']);
        }

        if (array_key_exists('batch_expiry_email_enabled', $validated)) {
            $settings['batch_expiry_email_enabled'] = (bool) $validated['batch_expiry_email_enabled'];
            unset($validated['batch_expiry_email_enabled']);
        }

        if (array_key_exists('default_service_charge_percent', $validated)) {
            $v = $validated['default_service_charge_percent'];
            $settings['default_service_charge_percent'] = $v === null || $v === ''
                ? 0
                : max(0, min(100, (float) $v));
            unset($validated['default_service_charge_percent']);
        }

        unset($validated['receipt_logo'], $validated['remove_receipt_logo']);

        $newReceiptLogo = $store->receipt_logo;

        if ($request->boolean('remove_receipt_logo')) {
            if ($store->receipt_logo) {
                Storage::disk('public')->delete($store->receipt_logo);
            }
            $newReceiptLogo = null;
            unset($settings['receipt_logo_url']);
        }

        if ($request->hasFile('receipt_logo')) {
            if ($store->receipt_logo) {
                Storage::disk('public')->delete($store->receipt_logo);
            }
            $newReceiptLogo = $request->file('receipt_logo')->store(
                'receipt-logos/'.$store->id,
                'public'
            );
            unset($settings['receipt_logo_url']);
        }

        if (($validated['business_type'] ?? '') === 'pharmacy') {
            $settings['batch_tracking_enabled'] = true;
        }

        if (empty($settings['batch_tracking_enabled'])) {
            $settings['batch_expiry_email_enabled'] = false;
        }

        $attachMode = $validated['organization_attach_mode'] ?? null;
        unset($validated['organization_attach_mode'], $validated['attach_organization_name'], $validated['attach_organization_id']);

        if (! $store->organization_id && $user->canManageStoreSettings()) {
            if ($attachMode === 'join') {
                $oid = (int) $request->input('attach_organization_id');
                if ($oid < 1) {
                    throw ValidationException::withMessages([
                        'attach_organization_id' => 'Pilih grup usaha untuk digabung.',
                    ]);
                }
                if (! $user->stores()->where('organization_id', $oid)->exists()) {
                    throw ValidationException::withMessages([
                        'attach_organization_id' => 'Anda tidak memiliki akses ke grup tersebut.',
                    ]);
                }
                $validated['organization_id'] = $oid;
            } elseif ($attachMode === 'new') {
                $orgName = trim((string) $request->input('attach_organization_name', ''));
                if ($orgName === '') {
                    $orgName = $store->name;
                }
                $baseSlug = Str::slug($orgName);
                if ($baseSlug === '') {
                    $baseSlug = 'grup';
                }
                $orgSlug = $baseSlug.'-'.Str::lower(Str::random(5));
                while (Organization::where('slug', $orgSlug)->exists()) {
                    $orgSlug = $baseSlug.'-'.Str::lower(Str::random(5));
                }
                $organization = Organization::create([
                    'name' => $orgName,
                    'slug' => $orgSlug,
                    'owner_user_id' => $user->id,
                ]);
                $validated['organization_id'] = $organization->id;
            }
        }

        if (array_key_exists('organization_name', $validated)) {
            $orgName = trim((string) $validated['organization_name']);
            unset($validated['organization_name']);
            $targetOrgId = $validated['organization_id'] ?? $store->organization_id;
            if ($targetOrgId && $orgName !== '') {
                Organization::query()->whereKey($targetOrgId)->update(['name' => $orgName]);
            }
        }

        $store->update([
            ...$validated,
            'receipt_logo' => $newReceiptLogo,
            'settings' => $settings,
        ]);

        if ($store->wasChanged('business_type')) {
            StoreDefaultProductCategories::ensureForStore($store);
        }

        // Logging perubahan modul ke module_activity_logs
        $this->logModuleChanges($store, $request);

        return back()->with('status', 'store-updated');
    }

    /**
     * Persiapkan data akuntansi saat modul pertama kali diaktifkan:
     * memastikan COA lengkap dan membuat periode bulan berjalan (open)
     * jika belum ada periode yang mencakup bulan berjalan.
     */
    public static function prepareAccountingForActivation(\App\Models\Store $store): void
    {
        ChartOfAccountsBootstrap::ensureForStore($store);

        $now = now();
        $period = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->whereDate('start_date', '<=', $now)
            ->whereDate('end_date', '>=', $now)
            ->first();

        if ($period) {
            return;
        }

        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();

        AccountingPeriod::query()->create([
            'store_id' => $store->id,
            'name' => $now->format('F Y'),
            'start_date' => $start,
            'end_date' => $end,
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
    }

    /**
     * Default values untuk recipes_enabled & productions_enabled per business_type.
     *
     * @return array{recipes_enabled: bool, productions_enabled: bool}
     */
    public static function featureDefaultsForType(string $type): array
    {
        return match ($type) {
            'fnb', 'bakery' => [
                'recipes_enabled' => true,
                'productions_enabled' => true,
            ],
            'pharmacy' => [
                'recipes_enabled' => true,
                'productions_enabled' => false,
            ],
            default => [
                'recipes_enabled' => false,
                'productions_enabled' => false,
            ],
        };
    }

    /**
     * Normalisasi nomor WhatsApp: bersihkan, pisah koma, dedup, kosong = ''.
     */
    public static function normalizeWhatsappNumbers(string $raw): string
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        foreach ($parts as $p) {
            $digits = preg_replace('/\D+/', '', $p) ?? '';
            if ($digits === '' && str_contains($p, '@')) {
                // JID utuh seperti 628xx@s.whatsapp.net — biarkan
                $digits = trim($p);
                if ($digits === '') {
                    continue;
                }
                $out[] = $digits;
            } elseif ($digits !== '') {
                $out[] = $digits;
            }
        }
        $out = array_values(array_unique(array_filter($out)));

        return implode(',', $out);
    }

    /**
     * Catat perubahan status modul ke module_activity_logs untuk audit trail.
     */
    protected function logModuleChanges(\App\Models\Store $store, \Illuminate\Http\Request $request): void
    {
        $moduleKeys = ModuleService::MODULES;
        $originalSettings = $store->getOriginal('settings') ?? [];
        $currentSettings = $store->settings ?? [];
        $userId = auth()->id();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        foreach ($moduleKeys as $key => $label) {
            $statusSebelum = (bool) ($originalSettings[$key] ?? false);
            $statusSesudah = (bool) ($currentSettings[$key] ?? false);

            if ($statusSebelum !== $statusSesudah) {
                \App\Models\ModuleActivityLog::create([
                    'store_id' => $store->id,
                    'user_id' => $userId,
                    'module_name' => $key,
                    'action' => $statusSesudah ? 'enabled' : 'disabled',
                    'status_sebelum' => $statusSebelum,
                    'status_sesudah' => $statusSesudah,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'keterangan' => "Modul \"{$label}\" ".($statusSesudah ? 'diaktifkan' : 'dinonaktifkan'),
                ]);

                // Juga catat ke ActivityLogger (general log)
                \App\Services\ActivityLogger::log(
                    store: $store,
                    action: $statusSesudah ? 'module.enabled' : 'module.disabled',
                    description: "Modul \"{$label}\" ".($statusSesudah ? 'diaktifkan' : 'dinonaktifkan').' oleh '.(auth()->user()?->name ?? 'System'),
                    metadata: [
                        'module_name' => $key,
                        'module_label' => $label,
                        'status_sebelum' => $statusSebelum,
                        'status_sesudah' => $statusSesudah,
                    ],
                );
            }
        }
    }
}
