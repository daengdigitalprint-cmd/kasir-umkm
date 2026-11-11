<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StoreSettingsController;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Services\AttendancePayrollAddOnService;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\BarbershopFeatures;
use App\Support\FnBFeatures;
use App\Support\LaundryFeatures;
use App\Support\PharmacyFeatures;
use App\Support\ProductDiscountFeatures;
use App\Support\StockAdjustmentApproval;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        // Resolve active store with fallback to first accessible store
        $activeStore = null;
        if ($user) {
            $activeStore = $user->store;
            if (! $activeStore) {
                $activeStore = $user->stores()->first();
            }
        }

        $accessibleStores = [];
        if ($user) {
            if ($user->isSuperAdmin()) {
                $accessibleStores = Store::query()
                    ->with(['organization:id,name'])
                    ->orderBy('name')
                    ->select(['id', 'organization_id', 'name', 'slug'])
                    ->limit(400)
                    ->get()
                    ->map(fn (Store $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'slug' => $s->slug,
                        'organization_id' => $s->organization_id,
                        'organization_name' => $s->organization?->name,
                    ])
                    ->values()
                    ->all();
            } else {
                $accessibleStores = $user->stores()
                    ->with(['organization:id,name'])
                    ->orderBy('stores.name')
                    ->get()
                    ->map(fn (Store $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'slug' => $s->slug,
                        'organization_id' => $s->organization_id,
                        'organization_name' => $s->organization?->name,
                    ])
                    ->values()
                    ->all();
            }
        }

        $subscriptionData = null;
        $planLimits = null;
        if ($user && $activeStore) {
            $store = $activeStore;
            $store->loadMissing('subscriptionPlan');
            $plan = StorePlanLimits::planForDisplay($store);
            $daysRemaining = $store->getDaysRemaining();
            $starterFree = StorePlanLimits::isStarterFreePlan($plan) && $store->is_subscription_active;

            $subscriptionData = [
                'is_active' => $store->isSubscriptionActive(),
                'days_remaining' => $starterFree ? null : $daysRemaining,
                'subscription_end' => $store->subscription_end?->toISOString(),
                'starter_free' => $starterFree,
                'status' => ! $store->is_subscription_active ? 'inactive'
                    : ($starterFree ? 'active'
                    : (! $store->subscription_end ? 'unlimited'
                    : ($daysRemaining <= 0 ? 'expired'
                    : ($daysRemaining <= 7 ? 'expiring' : 'active')))),
                'has_pending_payment' => $store->subscriptionPayments()->where('status', 'pending')->exists(),
            ];
            $planLimits = [
                'plan_name' => $plan?->name,
                'plan_slug' => $plan?->slug,
                'max_products' => $plan?->max_products,
                'max_customers' => $plan?->max_customers,
                'products_count' => StorePlanLimits::currentProductCount($store),
                'customers_count' => StorePlanLimits::currentCustomerCount($store),
                'allows_advanced_retail' => StorePlanLimits::allowsAdvancedRetailFeatures($store),
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'status' => fn () => $request->session()->get('status'),
                'show_product_modal' => fn () => $request->session()->get('show_product_modal'),
            ],
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'active_role' => $user?->getRoleForActiveStore(),
            ],
            'abilities' => [
                'backoffice' => $user?->canAccessBackOffice() ?? false,
                'inventory' => $user?->canAccessInventory() ?? false,
                'stock_adjustment' => $user?->canAccessStockAdjustment() ?? false,
                'pos' => $user?->canUsePointOfSale() ?? false,
                'cashier' => $user?->isCashier() ?? false,
                'manage_store_settings' => $user?->canManageStoreSettings() ?? false,
                // Pengelola SDM (absensi & gaji): owner, admin, manajer — sama dengan otorisasi server.
                'manage_hr' => $user ? ($user->isSuperAdmin() || in_array($user->getRoleForActiveStore(), ['admin', 'manager', 'owner'], true)) : false,
                'owner' => $user?->canAccessOwnerPanel() ?? false,
            ],
            'allowed_menus' => $user?->allowedMenus() ?? [],
            'fnb_enabled' => $activeStore ? FnBFeatures::enabled($activeStore) : false,
            'pharmacy_enabled' => $activeStore ? PharmacyFeatures::enabled($activeStore) : false,
            'laundry_enabled' => $activeStore ? LaundryFeatures::enabled($activeStore) : false,
            'barbershop_enabled' => $activeStore ? BarbershopFeatures::enabled($activeStore) : false,
            'attendance_enabled' => $activeStore ? AttendanceSettings::enabled($activeStore) : false,
            'attendance_settings' => $activeStore ? AttendanceSettings::forFrontend($activeStore) : null,
            'payroll_enabled' => $activeStore ? AttendancePayrollAddOnService::isPurchased($activeStore) : false,
            // Badge menu SDM: pengajuan cuti/lembur yang menunggu persetujuan (hanya untuk back-office).
            'pending_hr_requests' => fn () => $this->pendingHrRequests($user, $activeStore),
            'recipes_enabled' => $activeStore
                ? (bool) ($activeStore->settings['recipes_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($activeStore->business_type ?? 'general')['recipes_enabled'])
                : false,
            'productions_enabled' => $activeStore
                ? (bool) ($activeStore->settings['productions_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($activeStore->business_type ?? 'general')['productions_enabled'])
                : false,
            'kitchen_queue_enabled' => $activeStore
                ? (bool) ($activeStore->settings['kitchen_queue_enabled'] ?? false)
                : false,
            'queue_module_enabled' => $activeStore
                ? (bool) ($activeStore->settings['queue_module_enabled'] ?? false)
                : false,
            'scan_meja_enabled' => $activeStore
                ? (bool) ($activeStore->settings['scan_meja_enabled'] ?? false)
                : false,
            'accounting_enabled' => $activeStore ? AccountingFeatures::enabled($activeStore) : false,
            'receivables_enabled' => $activeStore
                ? (bool) ($activeStore->settings['receivables_enabled'] ?? true)
                : false,
            'product_discount_enabled' => ProductDiscountFeatures::enabled($activeStore),
            'ai_chat_enabled' => $activeStore
                ? (bool) ($activeStore->settings['ai_chat_enabled'] ?? true)
                : true,
            'pending_queue_count' => $activeStore && ($activeStore->settings['queue_module_enabled'] ?? false)
                ? QueueEntry::where('store_id', $activeStore->id)
                    ->whereNull('transaction_id')
                    ->where('status', 'waiting')
                    ->whereBetween('created_at', [today(), today()->endOfDay()])
                    ->count()
                : 0,
            'pending_kitchen_order_count' => $activeStore && ($activeStore->settings['kitchen_queue_enabled'] ?? false)
                ? QueueEntry::where('store_id', $activeStore->id)
                    ->whereNotNull('transaction_id')
                    ->where('status', 'waiting')
                    ->whereBetween('created_at', [today(), today()->endOfDay()])
                    ->count()
                : 0,
            'pending_table_order_count' => $activeStore && ($activeStore->settings['scan_meja_enabled'] ?? false)
                ? Transaction::where('store_id', $activeStore->id)
                    ->where('order_status', 'pending')
                    ->where('order_type', 'dine_in')
                    ->where(function ($q) {
                        $q->whereJsonContains('payment_details->source', 'scan_meja')
                            ->orWhere('payment_details->source', 'scan_meja');
                    })
                    ->count()
                : 0,
            'location_type' => $activeStore
                ? StoreLocationType::of($activeStore)
                : 'outlet',
            'direct_stock_edit' => $activeStore
                ? StockAdjustmentApproval::directStockEdit($activeStore)
                : false,
            'accessible_stores' => $accessibleStores,
            'subscription' => $subscriptionData,
            'plan_limits' => $planLimits,
            'can_access_activity_logs' => $user ? $user->canAccessActivityLogs() : false,
            'can_use_ai_assistant' => $user
                ? (($activeStore === null || ($activeStore->settings['ai_chat_enabled'] ?? true) !== false)
                    && ($user->isSuperAdmin() || $user->isAdmin() || $user->isManager() || $user->isCashier() || $user->isOwner()))
                : false,
            'can_manage_ai_settings' => $user ? ($user->isSuperAdmin() || $user->isAdmin()) : false,
            'cashier_login_mode' => $activeStore
                ? ($activeStore->cashier_login_mode ?? 'email')
                : 'email',
            'active_cashier' => $user ? $request->session()->get('active_cashier') : null,
            // Banner pengingat tutup buku: periode lampau yang masih terbuka
            // (hanya dihitung untuk user backoffice agar POS ringan).
            'unclosed_period_reminders' => ($user && $activeStore && $user->canAccessBackOffice())
                ? AccountingPeriodGuard::unclosedPastPeriods($activeStore)
                    ->map(fn ($period) => [
                        'id' => $period->id,
                        'name' => $period->name,
                        'end_date' => $period->end_date->toDateString(),
                    ])
                    ->values()
                    ->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'csrf_token' => csrf_token(),
            'app_url' => config('app.url'),
        ];
    }

    /**
     * Pengajuan cuti/lembur yang menunggu persetujuan.
     * `leave`/`overtime` = outlet aktif; `others` = outlet lain yang juga dikelola user
     * (owner multi-outlet), supaya pengajuan di outlet lain tidak terlewat.
     *
     * @return array{leave: int, overtime: int, total: int, others: list<array{id: int, name: string, leave: int, overtime: int}>}
     */
    private function pendingHrRequests(?User $user, ?Store $store): array
    {
        $empty = ['leave' => 0, 'overtime' => 0, 'total' => 0, 'others' => []];
        if (! $user || ! $store || ! $user->canAccessBackOffice()) {
            return $empty;
        }

        $stores = $user->isSuperAdmin()
            ? collect([$store])
            : $user->stores()->wherePivotIn('role', ['admin', 'manager', 'owner'])->where('stores.is_active', true)->get();
        if (! $stores->contains('id', $store->id)) {
            $stores->push($store);
        }
        $stores = $stores->filter(fn (Store $s) => AttendanceSettings::enabled($s))->keyBy('id');
        if ($stores->isEmpty()) {
            return $empty;
        }

        $ids = $stores->keys()->all();
        $leave = LeaveRequest::query()->whereIn('store_id', $ids)->where('status', 'pending')
            ->selectRaw('store_id, COUNT(*) as n')->groupBy('store_id')->pluck('n', 'store_id');
        $overtime = OvertimeRequest::query()->whereIn('store_id', $ids)->where('status', 'pending')
            ->selectRaw('store_id, COUNT(*) as n')->groupBy('store_id')->pluck('n', 'store_id');

        $others = $stores->except($store->id)
            ->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'leave' => (int) ($leave[$s->id] ?? 0),
                'overtime' => (int) ($overtime[$s->id] ?? 0),
            ])
            ->filter(fn (array $row) => $row['leave'] + $row['overtime'] > 0)
            ->values()
            ->all();

        return [
            'leave' => (int) ($leave[$store->id] ?? 0),
            'overtime' => (int) ($overtime[$store->id] ?? 0),
            'total' => (int) ($leave->sum() + $overtime->sum()),
            'others' => $others,
        ];
    }
}
