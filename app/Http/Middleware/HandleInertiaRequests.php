<?php

namespace App\Http\Middleware;

use App\Models\QueueEntry;
use App\Models\Store;
use App\Http\Controllers\StoreSettingsController;
use App\Support\FnBFeatures;
use App\Support\LaundryFeatures;
use App\Support\StorePlanLimits;
use App\Support\StockAdjustmentApproval;
use App\Support\StoreLocationType;
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
        if ($user && $user->store) {
            $store = $user->store;
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
            ],
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
            ],
            'abilities' => [
                'backoffice' => $user?->canAccessBackOffice() ?? false,
                'inventory' => $user?->canAccessInventory() ?? false,
                'stock_adjustment' => $user?->canAccessStockAdjustment() ?? false,
                'pos' => $user?->canUsePointOfSale() ?? false,
                'manage_store_settings' => $user?->canManageStoreSettings() ?? false,
                'owner' => $user?->canAccessOwnerPanel() ?? false,
            ],
            'fnb_enabled' => $user && $user->store ? FnBFeatures::enabled($user->store) : false,
            'laundry_enabled' => $user && $user->store ? LaundryFeatures::enabled($user->store) : false,
            'recipes_enabled' => $user && $user->store
                ? (bool) ($user->store->settings['recipes_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($user->store->business_type ?? 'general')['recipes_enabled'])
                : false,
            'productions_enabled' => $user && $user->store
                ? (bool) ($user->store->settings['productions_enabled']
                    ?? StoreSettingsController::featureDefaultsForType($user->store->business_type ?? 'general')['productions_enabled'])
                : false,
            'kitchen_queue_enabled' => $user && $user->store
                ? (bool) ($user->store->settings['kitchen_queue_enabled'] ?? false)
                : false,
            'queue_module_enabled' => $user && $user->store
                ? (bool) ($user->store->settings['queue_module_enabled'] ?? false)
                : false,
            'pending_queue_count' => $user && $user->store && ($user->store->settings['queue_module_enabled'] ?? false)
                ? QueueEntry::where('store_id', $user->store->id)
                    ->whereIn('status', ['waiting', 'serving', 'ready'])
                    ->count()
                : 0,
            'location_type' => $user && $user->store
                ? StoreLocationType::of($user->store)
                : 'outlet',
            'direct_stock_edit' => $user && $user->store
                ? StockAdjustmentApproval::directStockEdit($user->store)
                : false,
            'accessible_stores' => $accessibleStores,
            'subscription' => $subscriptionData,
            'plan_limits' => $planLimits,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'csrf_token' => csrf_token(),
            'app_url' => config('app.url'),
        ];
    }
}
