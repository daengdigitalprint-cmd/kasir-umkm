<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\BusinessTypePresets;
use App\Support\StockAdjustmentApproval;
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
            'direct_stock_edit' => 'sometimes|boolean',
            'warehouse_enabled' => 'sometimes|boolean',
            'queue_module_enabled' => 'sometimes|boolean',
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

        // Modul antrian: default off semua tipe bisnis
        if (array_key_exists('queue_module_enabled', $validated)) {
            $settings['queue_module_enabled'] = (bool) $validated['queue_module_enabled'];
            unset($validated['queue_module_enabled']);
        } elseif (! isset($settings['queue_module_enabled'])) {
            $settings['queue_module_enabled'] = false;
        }

        if (array_key_exists('website', $validated)) {
            $settings['website'] = $validated['website'] ?: null;
            unset($validated['website']);
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

        return back()->with('status', 'store-updated');
    }

    /**
     * Default values untuk recipes_enabled & productions_enabled per business_type.
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
}
