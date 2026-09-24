<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Organization;
use App\Support\Accounting\AccountingFeatures;
use App\Support\BusinessTypePresets;
use App\Support\FnBFeatures;
use App\Support\StockAdjustmentApproval;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $store = $user->store;
        if ($store) {
            $store->load('organization:id,name');
        }

        $teamMembers = [];
        if ($store && $user->canManageStoreSettings() && $user->isMemberOfStore($store)) {
            $teamMembers = $store->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'role' => $m->pivot->role,
                ])
                ->values()
                ->all();
        }

        $joinableOrganizations = [];
        if ($store && $store->organization_id === null && $user->canManageStoreSettings()) {
            $orgIds = $user->stores()
                ->whereNotNull('organization_id')
                ->pluck('organization_id')
                ->unique()
                ->filter()
                ->values()
                ->all();
            if ($orgIds !== []) {
                $joinableOrganizations = Organization::query()
                    ->whereIn('id', $orgIds)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])
                    ->values()
                    ->all();
            }
        }

        return Inertia::render('settings/Profile', [
            'user' => $user,
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'store' => $store ? [
                'id' => $store->id,
                'slug' => $store->slug,
                'name' => $store->name,
                'organization_id' => $store->organization_id,
                'organization_name' => $store->organization?->name ?? '',
                'team_members' => $teamMembers,
                'description' => $store->description ?? '',
                'address' => $store->address ?? '',
                'phone' => $store->phone ?? '',
                'email' => $store->email ?? '',
                'alert_whatsapp_numbers' => $store->settings['alert_whatsapp_numbers'] ?? '',
                'website' => $store->settings['website'] ?? '',
                'business_type' => $store->business_type ?? 'general',
                'tax_enabled' => $store->tax_enabled ?? false,
                'tax_rate' => $store->tax_rate ?? 11.00,
                'tax_name' => $store->tax_name ?? 'PPN',
                'receipt_header_name' => $store->settings['receipt_header_name'] ?? '',
                'receipt_subtitle' => $store->settings['receipt_subtitle'] ?? '',
                'receipt_footer' => $store->settings['receipt_footer'] ?? 'Terima kasih atas kunjungan Anda.',
                'receipt_footer_line2' => $store->settings['receipt_footer_line2'] ?? '',
                'receipt_logo_preview_url' => $store->receipt_logo && Storage::disk('public')->exists($store->receipt_logo)
                    ? Storage::disk('public')->url($store->receipt_logo)
                    : (trim((string) ($store->settings['receipt_logo_url'] ?? '')) !== ''
                        ? trim((string) $store->settings['receipt_logo_url'])
                        : null),
                'receipt_show_address' => (bool) ($store->settings['receipt_show_address'] ?? true),
                'receipt_show_phone' => (bool) ($store->settings['receipt_show_phone'] ?? true),
                'receipt_show_logo' => (bool) ($store->settings['receipt_show_logo'] ?? true),
                'receipt_paper_width' => $store->settings['receipt_paper_width'] ?? '80',
                'batch_tracking_enabled' => (bool) ($store->settings['batch_tracking_enabled'] ?? false),
                'batch_expiry_warn_days' => (int) ($store->settings['batch_expiry_warn_days'] ?? 30),
                'batch_expiry_email_enabled' => (bool) ($store->settings['batch_expiry_email_enabled'] ?? false),
                'default_service_charge_percent' => (float) ($store->settings['default_service_charge_percent'] ?? 0),
                'pos_show_barcode' => (bool) ($store->settings['pos_show_barcode'] ?? false),
                'recipes_enabled' => (bool) ($store->settings['recipes_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($store->business_type ?? 'general')['recipes_enabled']),
                'productions_enabled' => (bool) ($store->settings['productions_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($store->business_type ?? 'general')['productions_enabled']),
                'kitchen_queue_enabled' => (bool) ($store->settings['kitchen_queue_enabled'] ?? false),
                'queue_module_enabled' => (bool) ($store->settings['queue_module_enabled'] ?? false),
                'fnb_order_types_enabled' => (bool) ($store->settings['fnb_order_types_enabled'] ?? true),
                'fnb_split_bill_enabled' => (bool) ($store->settings['fnb_split_bill_enabled'] ?? true),
                'open_bill_enabled' => FnBFeatures::openBillEnabled($store),
                'direct_stock_edit' => StockAdjustmentApproval::directStockEdit($store),
                'warehouse_enabled' => (bool) ($store->settings['warehouse_enabled'] ?? false),
                'is_allow_local_purchase' => (bool) ($store->settings['is_allow_local_purchase'] ?? false),
                'online_sales_enabled' => (bool) ($store->settings['online_sales_enabled'] ?? false),
                'online_commission_gofood' => (float) ($store->settings['online_commission_gofood'] ?? 20),
                'online_commission_grabfood' => (float) ($store->settings['online_commission_grabfood'] ?? 20),
                'online_commission_shopeefood' => (float) ($store->settings['online_commission_shopeefood'] ?? 15),
                'attendance_enabled' => (bool) ($store->settings['attendance']['enabled'] ?? false),
                'wholesale_enabled' => (bool) ($store->settings['wholesale_enabled'] ?? false),
                'cup_label_enabled' => (bool) ($store->settings['cup_label_enabled'] ?? false),
                'scan_meja_enabled' => (bool) ($store->settings['scan_meja_enabled'] ?? false),
                'accounting_enabled' => AccountingFeatures::enabled($store),
                'max_backdate_days' => (int) ($store->settings['max_backdate_days'] ?? 7),
                'receivables_enabled' => (bool) ($store->settings['receivables_enabled'] ?? true),
                'scan_meja_payment_methods' => $store->settings['scan_meja_payment_methods'] ?? [
                    ['type' => 'cash', 'label' => 'Tunai', 'active' => true, 'instructions' => 'Bayar langsung di kasir'],
                    ['type' => 'qris', 'label' => 'QRIS', 'active' => true, 'image' => null],
                    ['type' => 'bank_transfer', 'label' => 'Transfer Bank', 'active' => false, 'bank_name' => '', 'account_number' => '', 'account_name' => ''],
                ],
                'cashier_login_mode' => $store->cashier_login_mode ?? 'email',
            ] : null,
            'joinableOrganizations' => $joinableOrganizations,
            'businessTypes' => BusinessTypePresets::TYPES,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
