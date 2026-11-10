<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Store;
use App\Support\BusinessTypePresets;
use App\Support\InventoryType;
use App\Support\StoreDefaultProductCategories;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Inertia\Inertia;

class StoreController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('role.store_admin', except: ['create', 'store']),
        ];
    }

    public function index()
    {
        $stores = Store::with('users')->get();

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
        ]);
    }

    public function create()
    {
        $user = auth()->user();

        $joinableOrganizations = [];
        if ($user) {
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

        $centralWarehouses = [];
        foreach ($joinableOrganizations as $org) {
            $wh = StoreLocationType::centralWarehouseFor((int) $org['id']);
            if ($wh) {
                $centralWarehouses[$org['id']] = ['id' => $wh->id, 'name' => $wh->name, 'product_count' => $wh->products()->count()];
            }
        }

        // Ringkasan kuota outlet per grup usaha (Gudang Pusat tidak ikut kuota).
        $outletQuotas = [];
        foreach ($joinableOrganizations as $org) {
            $oid = (int) $org['id'];
            $max = StorePlanLimits::maxOutletsAllowedForOrganization($oid);
            $outletQuotas[$oid] = [
                'used' => Store::where('organization_id', $oid)->outlet()->count(),
                'limit' => $max, // null = tidak dibatasi
                'has_warehouse' => isset($centralWarehouses[$oid]),
            ];
        }

        return Inertia::render('Stores/Create', [
            'businessTypes' => BusinessTypePresets::TYPES,
            'defaultBusinessType' => $user->business_type ?? 'general',
            'hasExistingStores' => $user->stores()->exists(),
            'joinableOrganizations' => $joinableOrganizations,
            'centralWarehouses' => $centralWarehouses,
            'outletQuotas' => $outletQuotas,
            'locationTypes' => StoreLocationType::LABELS,
            'existingStores' => $user->stores()
                ->with('organization:id,name')
                ->orderBy('stores.name')
                ->get([
                    'stores.id',
                    'stores.slug',
                    'stores.name',
                    'stores.location_type',
                    'stores.is_primary',
                    'stores.organization_id',
                    'stores.is_active',
                ])
                ->map(fn (Store $s) => [
                    'id' => $s->id,
                    'slug' => $s->slug,
                    'name' => $s->name,
                    'location_type' => $s->location_type,
                    'location_label' => StoreLocationType::LABELS[$s->location_type] ?? $s->location_type,
                    'is_primary' => (bool) $s->is_primary,
                    'is_active' => $s->is_active,
                    'group_name' => $s->organization?->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type' => 'required|string|in:'.implode(',', array_keys(BusinessTypePresets::TYPES)),
            'location_type' => 'nullable|string|in:'.implode(',', array_keys(StoreLocationType::LABELS)),
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'currency' => 'required|string|size:3',
            'timezone' => 'required|string|max:50',
            'organization_mode' => 'required|string|in:new,join',
            'organization_name' => 'nullable|string|max:255',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'copy_products' => 'nullable|boolean',
        ], [
            'name.unique' => 'Nama toko ini sudah digunakan, silakan pilih nama lain.',
        ]);

        // Slug dari nama toko harus unik; jika bentuk dasar sudah dipakai, tambah sufiks acak (sama pola dengan slug organisasi).
        $baseSlug = Str::slug($validated['name']);
        if ($baseSlug === '') {
            $baseSlug = 'toko';
        }
        $slug = $baseSlug;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.Str::lower(Str::random(5));
        }

        $owner = auth()->user();
        if (! $owner) {
            abort(403);
        }

        $organizationId = null;
        if (($validated['organization_mode'] ?? 'new') === 'join') {
            $oid = (int) ($validated['organization_id'] ?? 0);
            if ($oid < 1) {
                return back()->withErrors(['organization_id' => 'Pilih grup usaha untuk digabung.'])->withInput();
            }
            $allowed = $owner->stores()
                ->where('organization_id', $oid)
                ->exists();
            if (! $allowed) {
                return back()->withErrors(['organization_id' => 'Anda tidak memiliki akses ke grup tersebut.'])->withInput();
            }
            $organizationId = $oid;
        } else {
            $orgName = trim((string) ($validated['organization_name'] ?? ''));
            if ($orgName === '') {
                $orgName = $validated['name'];
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
                'owner_user_id' => $owner->id,
            ]);
            $organizationId = $organization->id;
        }

        $locationType = StoreLocationType::normalize($validated['location_type'] ?? null);
        if ($locationType === StoreLocationType::CENTRAL_WAREHOUSE) {
            // Gudang Pusat: gratis & terpisah dari kuota outlet, tapi maks 1 per grup usaha.
            StoreLocationType::assertOrganizationHasNoCentralWarehouse((int) $organizationId);
        } else {
            // Outlet: tunduk pada kuota max_stores + extra_outlets paket.
            // Fallback ke paket toko utama pembuat agar grup baru/kosong tetap dibatasi.
            $fallbackPlan = $owner->store?->subscriptionPlan;
            StorePlanLimits::assertCanAddStoreToOrganization((int) $organizationId, $fallbackPlan);
        }

        unset($validated['organization_mode'], $validated['organization_name'], $validated['organization_id']);

        $featureDefaults = StoreSettingsController::featureDefaultsForType($validated['business_type'] ?? 'general');

        $store = Store::create([
            ...$validated,
            'slug' => $slug,
            'location_type' => $locationType,
            'organization_id' => $organizationId,
            'settings' => [
                'tax_rate' => 0,
                'receipt_footer' => 'Terima kasih telah berbelanja!',
                ...$featureDefaults,
            ],
        ]);

        // Wariskan paket langganan dari outlet lain dalam organisasi yang sama
        // jika sudah aktif (bukan Starter/trial), agar outlet baru mengikuti paket utama.
        $inherited = false;
        if ($organizationId) {
            $sibling = Store::where('organization_id', $organizationId)
                ->where('id', '!=', $store->id)
                ->where('is_subscription_active', true)
                ->whereNotNull('subscription_end')
                ->where('subscription_end', '>', now())
                ->whereHas('subscriptionPlan', fn ($q) => $q->where('slug', '!=', 'starter'))
                ->orderByDesc('subscription_end')
                ->first();

            if ($sibling) {
                $store->forceFill([
                    'subscription_plan_id' => $sibling->subscription_plan_id,
                    'subscription_start' => now(),
                    'subscription_end' => $sibling->subscription_end,
                    'is_subscription_active' => true,
                    'subscription_notes' => "Warisan paket dari {$sibling->name}.",
                ])->save();
                $inherited = true;
            }
        }

        if (! $inherited) {
            $store->applyInitialStarterTrialFromPlans();
        }

        // Outlet pertama di grup usaha otomatis menjadi Outlet Utama.
        if ($locationType === StoreLocationType::OUTLET && ! Store::where('organization_id', $organizationId)->outlet()->where('is_primary', true)->exists()) {
            $store->markAsPrimary();
        }

        $owner->update([
            'store_id' => $store->id,
            'role' => 'admin',
        ]);
        $owner->stores()->syncWithoutDetaching([
            $store->id => ['role' => 'admin'],
        ]);

        // Salin produk bahan baku & kemasan dari Gudang Pusat jika dipilih
        $copyProducts = (bool) ($validated['copy_products'] ?? false);
        if ($copyProducts && $organizationId) {
            $centralWarehouse = StoreLocationType::centralWarehouseFor((int) $organizationId);
            if ($centralWarehouse) {
                $store->copyProductsFrom($centralWarehouse);
            }
        }

        $response = redirect()->route('dashboard')->with('success', 'Toko berhasil dibuat!');

        // Modal ajakan buat produk pertama hanya jika toko benar-benar belum punya produk
        // (misal user memilih salin produk dari gudang pusat, modal tidak perlu ditampilkan).
        if (! $store->products()->exists()) {
            $response->with('show_product_modal', true);
        }

        return $response;
    }

    public function show(Store $store)
    {
        $store->load(['products', 'customers', 'transactions']);

        return Inertia::render('Stores/Show', [
            'store' => $store,
        ]);
    }

    public function edit(Store $store)
    {
        return Inertia::render('Stores/Edit', [
            'store' => $store,
            'locationTypes' => StoreLocationType::LABELS,
        ]);
    }

    public function update(Request $request, Store $store)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_type' => 'required|string|in:'.implode(',', array_keys(BusinessTypePresets::TYPES)),
            'location_type' => 'nullable|string|in:'.implode(',', array_keys(StoreLocationType::LABELS)),
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'currency' => 'required|string|size:3',
            'timezone' => 'required|string|max:50',
            'settings' => 'nullable|array',
        ]);

        if (array_key_exists('location_type', $validated)) {
            $locationType = StoreLocationType::normalize($validated['location_type']);
            if (
                $locationType === StoreLocationType::CENTRAL_WAREHOUSE
                && ! StoreLocationType::isCentralWarehouse($store)
            ) {
                StoreLocationType::assertOrganizationHasNoCentralWarehouse(
                    $store->organization_id !== null ? (int) $store->organization_id : null,
                    $store->id,
                );
            }
            $validated['location_type'] = $locationType;
        }

        $store->update($validated);

        if ($store->wasChanged('business_type')) {
            StoreDefaultProductCategories::ensureForStore($store);
        }

        return redirect()->route('stores.show', $store)
            ->with('success', 'Toko berhasil diperbarui!');
    }

    public function destroy(Store $store)
    {
        if ($store->is_primary) {
            return back()->with('error', 'Outlet utama tidak dapat dihapus. Jadikan outlet lain sebagai utama terlebih dahulu.');
        }

        $store->delete();

        return back()->with('success', 'Outlet berhasil dihapus!');
    }

    public function manageStaff()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return back()->with('error', 'Tidak ada toko aktif.');
        }

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

        return Inertia::render('Stores/ManageStaff', [
            'store' => [
                'id' => $store->id,
                'slug' => $store->slug,
                'name' => $store->name,
                'team_members' => $teamMembers,
            ],
        ]);
    }

    public function manageProducts()
    {
        $user = auth()->user();
        $organizationId = $user->store?->organization_id;

        if (! $organizationId) {
            return back()->with('error', 'Toko belum tergabung dalam grup usaha.');
        }

        $centralWarehouse = StoreLocationType::centralWarehouseFor((int) $organizationId);

        $outlets = Store::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('location_type', '!=', StoreLocationType::CENTRAL_WAREHOUSE)
            ->orderBy('name')
            ->get(['id', 'name', 'location_type']);

        // Sumber produk: Gudang Pusat (jika ada) + Outlet Utama + Toko Admin yang sedang aktif
        $primaryStore = Store::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('is_primary', true)
            ->first();

        $sources = [];
        $seenSourceIds = [];

        $addSource = function (?Store $candidate, string $type) use (&$sources, &$seenSourceIds) {
            if (! $candidate || in_array($candidate->id, $seenSourceIds, true)) {
                return;
            }

            $counts = $this->countProductsByType($candidate);
            $seenSourceIds[] = $candidate->id;
            $sources[] = [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'type' => $type,
                'raw_material_count' => $counts['raw_material'],
                'sellable_count' => $counts['sellable'],
                'total_count' => $counts['raw_material'] + $counts['sellable'],
            ];
        };

        $addSource($centralWarehouse, 'central_warehouse');
        $addSource($primaryStore, 'primary_outlet');
        $addSource($user->store, 'admin_store');

        $defaultSourceId = $sources[0]['id'] ?? null;

        return Inertia::render('Stores/ManageProducts', [
            'sources' => $sources,
            'defaultSourceId' => $defaultSourceId,
            'outlets' => $outlets->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'location_type' => $o->location_type,
                'current_product_count' => $o->products()->count(),
            ]),
        ]);
    }

    private function countProductsByType(Store $store): array
    {
        $counts = $store->products()->where('is_active', true)
            ->selectRaw('inventory_type, count(*) as cnt')
            ->groupBy('inventory_type')
            ->pluck('cnt', 'inventory_type')
            ->all();

        $rawMaterial = ($counts[InventoryType::RAW_MATERIAL] ?? 0) + ($counts[InventoryType::PACKAGING] ?? 0);
        $sellable = ($counts[InventoryType::SELLABLE] ?? 0) + ($counts[InventoryType::PRODUCTION_OUTPUT] ?? 0);

        return ['raw_material' => $rawMaterial, 'sellable' => $sellable];
    }

    public function copyProductsToOutlets(Request $request)
    {
        $validated = $request->validate([
            'outlet_ids' => 'required|array|min:1',
            'outlet_ids.*' => 'required|integer|exists:stores,id',
            'source_store_id' => 'required|integer|exists:stores,id',
            'copy_type' => 'required|string|in:raw_material,sellable,all',
        ]);

        $user = auth()->user();
        $organizationId = $user->store?->organization_id;

        if (! $organizationId) {
            return response()->json(['error' => 'Toko belum tergabung dalam grup usaha.'], 422);
        }

        // Validasi sumber harus dalam organisasi yang sama
        $sourceStore = Store::find($validated['source_store_id']);
        if (! $sourceStore || (int) $sourceStore->organization_id !== (int) $organizationId) {
            return response()->json(['error' => 'Sumber penyalinan tidak valid atau bukan bagian dari grup usaha ini.'], 422);
        }

        $results = [];
        $totalCopied = 0;
        $totalSkipped = 0;
        $totalComponents = 0;
        $allErrors = [];

        foreach ($validated['outlet_ids'] as $outletId) {
            $outlet = Store::find($outletId);
            if (! $outlet || (int) $outlet->organization_id !== (int) $organizationId) {
                $allErrors[] = "Outlet ID {$outletId}: bukan bagian dari grup usaha ini.";
                $results[] = ['outlet_name' => "Outlet #{$outletId}", 'copied' => 0, 'skipped' => 0, 'components' => 0, 'errors' => ['Bukan bagian dari grup usaha ini']];

                continue;
            }
            if ($outlet->id === $sourceStore->id) {
                $allErrors[] = "{$outlet->name}: tidak bisa menyalin ke diri sendiri.";
                $results[] = ['outlet_name' => $outlet->name, 'copied' => 0, 'skipped' => 0, 'components' => 0, 'errors' => ['Tidak bisa menyalin ke diri sendiri']];

                continue;
            }

            try {
                $result = $outlet->copyProductsFrom($sourceStore, $validated['copy_type']);
                $totalCopied += $result['copied'];
                $totalSkipped += $result['skipped'];
                $totalComponents += $result['components'];
                $allErrors = array_merge($allErrors, $result['errors']);
                $results[] = [
                    'outlet_name' => $outlet->name,
                    'copied' => $result['copied'],
                    'skipped' => $result['skipped'],
                    'components' => $result['components'],
                    'errors' => $result['errors'],
                ];
            } catch (\Throwable $e) {
                $allErrors[] = "{$outlet->name}: {$e->getMessage()}";
                $results[] = ['outlet_name' => $outlet->name, 'copied' => 0, 'skipped' => 0, 'components' => 0, 'errors' => [$e->getMessage()]];
            }
        }

        return response()->json([
            'success' => count($allErrors) === 0,
            'total_copied' => $totalCopied,
            'total_skipped' => $totalSkipped,
            'total_components' => $totalComponents,
            'outlet_count' => count($validated['outlet_ids']),
            'results' => $results,
            'errors' => $allErrors,
        ]);
    }
}
