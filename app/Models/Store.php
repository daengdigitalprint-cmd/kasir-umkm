<?php

namespace App\Models;

use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\InventoryType;
use App\Support\StoreDefaultProductCategories;
use App\Support\StoreLocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'business_type',
        'location_type',
        'is_primary',
        'description',
        'address',
        'phone',
        'email',
        'logo',
        'receipt_logo',
        'currency',
        'timezone',
        'settings',
        'is_active',
        'tax_enabled',
        'tax_rate',
        'tax_name',
        'subscription_start',
        'subscription_end',
        'subscription_days',
        'is_subscription_active',
        'subscription_notes',
        'subscription_plan_id',
        'pro_trial_used',
        'pro_trial_active',
        'pro_trial_start',
        'pro_trial_end',
        'extra_outlets',
        'attendance_payroll_until',
        'late_penalty_per_day',
        'late_penalty_per_minute',
        'late_penalty_method',
        'overtime_rate_first_hour',
        'overtime_rate_next_hour',
        'overtime_rate_holiday',
        'bpjs_enabled',
        'bpjs_health_employee',
        'bpjs_health_employer',
        'bpjs_jht_employee',
        'bpjs_jht_employer',
        'bpjs_jp_employee',
        'bpjs_jp_employer',
        'bpjs_jkk',
        'bpjs_jkm',
        'pph21_enabled',
        'bpjs_health_wage_cap',
        'bpjs_jp_wage_cap',
        'absence_deduction_enabled',
        'cashier_login_mode',
    ];

    protected $attributes = [
        'location_type' => StoreLocationType::OUTLET,
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:2',
        'subscription_start' => 'datetime',
        'subscription_end' => 'datetime',
        'is_subscription_active' => 'boolean',
        'pro_trial_used' => 'boolean',
        'pro_trial_active' => 'boolean',
        'pro_trial_start' => 'datetime',
        'pro_trial_end' => 'datetime',
        'extra_outlets' => 'integer',
        'attendance_payroll_until' => 'datetime',
        'late_penalty_per_day' => 'decimal:2',
        'late_penalty_per_minute' => 'decimal:2',
        'overtime_rate_first_hour' => 'decimal:2',
        'overtime_rate_next_hour' => 'decimal:2',
        'overtime_rate_holiday' => 'decimal:2',
        'bpjs_enabled' => 'boolean',
        'bpjs_health_employee' => 'decimal:2',
        'bpjs_health_employer' => 'decimal:2',
        'bpjs_jht_employee' => 'decimal:2',
        'bpjs_jht_employer' => 'decimal:2',
        'bpjs_jp_employee' => 'decimal:2',
        'bpjs_jp_employer' => 'decimal:2',
        'bpjs_jkk' => 'decimal:2',
        'bpjs_jkm' => 'decimal:2',
        'bpjs_health_wage_cap' => 'decimal:2',
        'bpjs_jp_wage_cap' => 'decimal:2',
        'absence_deduction_enabled' => 'boolean',
        'pph21_enabled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            }
        });

        static::created(function (Store $store) {
            StoreDefaultProductCategories::ensureForStore($store);
            ChartOfAccountsBootstrap::ensureForStore($store);

            // Owner grup usaha otomatis mendapat akses ke outlet baru
            if ($store->organization_id) {
                $ownerUserIds = \Illuminate\Support\Facades\DB::table('store_user')
                    ->join('stores', 'stores.id', '=', 'store_user.store_id')
                    ->where('stores.organization_id', $store->organization_id)
                    ->where('store_user.role', 'owner')
                    ->distinct()
                    ->pluck('store_user.user_id');

                foreach ($ownerUserIds as $userId) {
                    \Illuminate\Support\Facades\DB::table('store_user')->updateOrInsert(
                        [
                            'store_id' => $store->id,
                            'user_id' => $userId,
                        ],
                        [
                            'role' => 'owner',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Grup usaha / pemilik beberapa outlet (Fase F1). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Anggota terdaftar (multi-outlet); peran per toko di pivot. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Salin produk dari sumber ke toko ini, lengkap dengan varian, komponen paket,
     * komponen konsumsi penjualan, dan resep produksi.
     *
     * @param  string  $copyType  'raw_material' | 'sellable' | 'all'
     * @return array{copied: int, skipped: int, components: int, errors: string[], details: array}
     */
    public function copyProductsFrom(Store $source, string $copyType = 'all'): array
    {
        // Bangun lookup duplikasi: SKU (jika ada) + nama+inventory_type sebagai fallback
        $destProducts = $this->products()->get();
        $destBySku = $destProducts->filter(fn ($p) => filled($p->sku))->keyBy('sku');
        $destByName = $destProducts->keyBy(fn ($p) => $p->name.'|'.$p->inventory_type);

        $query = $source->products()->where('is_active', true);

        $query->when($copyType === 'raw_material', fn ($q) => $q->whereIn('inventory_type', [
            InventoryType::RAW_MATERIAL,
            InventoryType::PACKAGING,
        ]));

        $query->when($copyType === 'sellable', fn ($q) => $q->whereIn('inventory_type', [
            InventoryType::SELLABLE,
            InventoryType::PRODUCTION_OUTPUT,
        ]));

        // 'all' — tidak perlu filter, ambil semua produk aktif

        $sourceProducts = $query
            ->with([
                'productCategory',
                'variants',
                'bundleItems.componentProduct',
                'bundleItems.componentVariant',
                'saleConsumptions.component',
                'saleConsumptions.variant',
            ])
            ->orderBy('name')
            ->get();

        $copied = 0;
        $skipped = 0;
        $components = 0;
        $errors = [];
        $details = [];

        /** @var array<int, Product> produk sumber -> produk tujuan (baru maupun yang sudah ada) */
        $productMap = [];
        /** @var array<int, bool> produk sumber yang baru dibuat di toko ini */
        $freshProducts = [];

        // Tahap 1 — salin baris produk lebih dulu agar semua komponen punya acuan.
        foreach ($sourceProducts as $sp) {
            $existing = (filled($sp->sku) ? $destBySku->get($sp->sku) : null)
                ?? $destByName->get($sp->name.'|'.$sp->inventory_type);

            if ($existing) {
                $productMap[$sp->id] = $existing;
                $skipped++;
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'skipped'];

                continue;
            }

            try {
                $catId = null;
                if ($sp->product_category_id) {
                    $catName = $sp->productCategory?->name ?? $sp->category;
                    if ($catName) {
                        $cat = ProductCategory::query()->firstOrCreate(
                            ['store_id' => $this->id, 'name' => $catName],
                            ['sort_order' => 0],
                        );
                        $catId = $cat->id;
                    }
                }

                $newProduct = Product::query()->create([
                    'store_id' => $this->id,
                    'name' => $sp->name,
                    'sku' => $sp->sku,
                    'barcode' => $sp->barcode,
                    'description' => $sp->description,
                    'price' => $sp->price,
                    'wholesale_price' => $sp->wholesale_price,
                    'cost_price' => $sp->cost_price,
                    'average_cost' => $sp->average_cost,
                    'stock_quantity' => 0,
                    'min_stock_level' => $sp->min_stock_level,
                    'product_category_id' => $catId,
                    'category' => $sp->category,
                    'brand' => $sp->brand,
                    'unit' => $sp->unit,
                    'base_unit' => $sp->base_unit,
                    'images' => $sp->images,
                    'inventory_type' => $sp->inventory_type,
                    'service_duration_minutes' => $sp->service_duration_minutes,
                    'barber_commission_mode' => $sp->barber_commission_mode,
                    'barber_commission_value' => $sp->barber_commission_value,
                    'modifier_options' => $sp->modifier_options,
                    'pharmacy_sale_class' => $sp->pharmacy_sale_class,
                    'pharmacy_registration_no' => $sp->pharmacy_registration_no,
                    'tax_rate' => 0,
                    'is_active' => true,
                    'track_stock' => $sp->track_stock,
                    'unlimited_stock' => $sp->unlimited_stock,
                    'allow_negative_stock' => $sp->allow_negative_stock,
                    'has_variants' => false,
                    'is_bundle' => false,
                    'allows_decimal' => $sp->allows_decimal,
                ]);

                $productMap[$sp->id] = $newProduct;
                $freshProducts[$sp->id] = true;

                if (filled($newProduct->sku)) {
                    $destBySku->put($newProduct->sku, $newProduct);
                }
                $destByName->put($newProduct->name.'|'.$newProduct->inventory_type, $newProduct);

                $copied++;
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'copied'];
            } catch (\Throwable $e) {
                $errors[] = "{$sp->name}: {$e->getMessage()}";
                $details[] = ['name' => $sp->name, 'sku' => $sp->sku, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        // Tahap 2 — salin varian & komponen setelah semua produk tujuan tersedia.
        /** @var array<int, int> varian sumber -> varian tujuan */
        $variantMap = [];

        $resolveComponent = function (?Product $srcComponent) use (&$productMap, $destBySku, $destByName): ?Product {
            if (! $srcComponent) {
                return null;
            }

            return $productMap[$srcComponent->id]
                ?? (filled($srcComponent->sku) ? $destBySku->get($srcComponent->sku) : null)
                ?? $destByName->get($srcComponent->name.'|'.$srcComponent->inventory_type);
        };

        $resolveVariant = function (?ProductVariant $srcVariant, ?Product $destOwner) use (&$variantMap): ?int {
            if (! $srcVariant) {
                return null;
            }

            if (isset($variantMap[$srcVariant->id])) {
                return $variantMap[$srcVariant->id];
            }

            if (! $destOwner) {
                return null;
            }

            $match = $destOwner->variants()
                ->when(
                    filled($srcVariant->sku),
                    fn ($q) => $q->where('sku', $srcVariant->sku),
                    fn ($q) => $q->where('name', $srcVariant->name),
                )
                ->first();

            if ($match) {
                $variantMap[$srcVariant->id] = $match->id;
            }

            return $match?->id;
        };

        foreach ($sourceProducts as $sp) {
            $destProduct = $productMap[$sp->id] ?? null;

            if (! $destProduct) {
                continue;
            }

            // Produk yang sudah ada hanya diisi bila komponennya masih kosong,
            // supaya penyesuaian khusus outlet tidak tertimpa.
            $isFresh = isset($freshProducts[$sp->id]);
            $touched = false;

            try {
                if ($sp->variants->isNotEmpty()) {
                    foreach ($sp->variants as $sv) {
                        if ($resolveVariant($sv, $destProduct) !== null) {
                            continue;
                        }

                        $destVariant = ProductVariant::query()->create([
                            'store_id' => $this->id,
                            'product_id' => $destProduct->id,
                            'name' => $sv->name,
                            'sku' => $sv->sku,
                            'barcode' => $sv->barcode,
                            'price' => $sv->price,
                            'wholesale_price' => $sv->wholesale_price,
                            'cost_price' => $sv->cost_price,
                            'stock_quantity' => 0,
                            'min_stock_level' => $sv->min_stock_level,
                            'is_active' => $sv->is_active,
                            'sort_order' => $sv->sort_order,
                            'allow_negative_stock' => $sv->allow_negative_stock,
                        ]);

                        $variantMap[$sv->id] = $destVariant->id;
                        $touched = true;
                    }

                    if (! $destProduct->has_variants) {
                        $destProduct->update(['has_variants' => true]);
                    }
                }

                // Komponen paket (produk bundle)
                if ($sp->bundleItems->isNotEmpty() && ($isFresh || $destProduct->bundleItems()->count() === 0)) {
                    foreach ($sp->bundleItems as $item) {
                        $destComponent = $resolveComponent($item->componentProduct);

                        if (! $destComponent) {
                            $errors[] = "{$sp->name}: komponen paket \"{$item->componentProduct?->name}\" belum ada di toko tujuan.";

                            continue;
                        }

                        ProductBundleItem::query()->create([
                            'bundle_product_id' => $destProduct->id,
                            'component_product_id' => $destComponent->id,
                            'component_product_variant_id' => $resolveVariant($item->componentVariant, $destComponent),
                            'quantity' => $item->quantity,
                            'sort_order' => $item->sort_order,
                        ]);

                        $touched = true;
                    }

                    if (! $destProduct->is_bundle) {
                        $destProduct->update(['is_bundle' => true]);
                    }
                }

                // Komponen konsumsi penjualan (komponen menu)
                if ($sp->saleConsumptions->isNotEmpty() && ($isFresh || $destProduct->saleConsumptions()->count() === 0)) {
                    foreach ($sp->saleConsumptions as $consumption) {
                        $destComponent = $resolveComponent($consumption->component);

                        if (! $destComponent) {
                            $errors[] = "{$sp->name}: komponen \"{$consumption->component?->name}\" belum ada di toko tujuan.";

                            continue;
                        }

                        $destVariantId = null;
                        if ($consumption->product_variant_id) {
                            $destVariantId = $resolveVariant($consumption->variant, $destProduct);

                            if (! $destVariantId) {
                                $errors[] = "{$sp->name}: varian untuk komponen \"{$consumption->component?->name}\" tidak ditemukan di toko tujuan.";

                                continue;
                            }
                        }

                        ProductSaleConsumption::query()->create([
                            'store_id' => $this->id,
                            'product_id' => $destProduct->id,
                            'product_variant_id' => $destVariantId,
                            'component_product_id' => $destComponent->id,
                            'quantity' => $consumption->quantity,
                            'base_unit' => $consumption->base_unit,
                            'sort_order' => $consumption->sort_order,
                        ]);

                        $touched = true;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "{$sp->name} (komponen): {$e->getMessage()}";
            }

            if ($touched) {
                $components++;
            }
        }

        // Tahap 3 — salin resep produksi milik produk yang ikut tersalin.
        $components += $this->copyRecipesFrom($source, $productMap, $resolveComponent, $errors);

        return [
            'copied' => $copied,
            'skipped' => $skipped,
            'components' => $components,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /**
     * Salin resep produksi beserta bahannya untuk produk yang sudah dipetakan.
     *
     * @param  array<int, Product>  $productMap  produk sumber -> produk tujuan
     * @param  string[]  $errors
     */
    private function copyRecipesFrom(Store $source, array $productMap, callable $resolveComponent, array &$errors): int
    {
        if ($productMap === []) {
            return 0;
        }

        $sourceRecipes = Recipe::query()
            ->where('store_id', $source->id)
            ->whereIn('product_id', array_keys($productMap))
            ->with('ingredients.product')
            ->get();

        $copied = 0;

        foreach ($sourceRecipes as $recipe) {
            $destProduct = $productMap[$recipe->product_id] ?? null;

            if (! $destProduct) {
                continue;
            }

            $alreadyThere = Recipe::query()
                ->where('store_id', $this->id)
                ->where(fn ($q) => $q->where('product_id', $destProduct->id)
                    ->when(filled($recipe->code), fn ($qq) => $qq->orWhere('code', $recipe->code)))
                ->exists();

            if ($alreadyThere) {
                continue;
            }

            try {
                $destRecipe = Recipe::query()->create([
                    'store_id' => $this->id,
                    'name' => $recipe->name,
                    'code' => $recipe->code,
                    'product_id' => $destProduct->id,
                    'output_quantity' => $recipe->output_quantity,
                    'output_unit' => $recipe->output_unit,
                    'is_active' => $recipe->is_active,
                    'version' => $recipe->version,
                ]);

                foreach ($recipe->ingredients as $ingredient) {
                    $destIngredient = $resolveComponent($ingredient->product);

                    if (! $destIngredient) {
                        $errors[] = "Resep {$recipe->name}: bahan \"{$ingredient->product?->name}\" belum ada di toko tujuan.";

                        continue;
                    }

                    RecipeIngredient::query()->create([
                        'recipe_id' => $destRecipe->id,
                        'product_id' => $destIngredient->id,
                        'quantity' => $ingredient->quantity,
                        'base_unit' => $ingredient->base_unit,
                        'sort_order' => $ingredient->sort_order,
                    ]);
                }

                $copied++;
            } catch (\Throwable $e) {
                $errors[] = "Resep {$recipe->name}: {$e->getMessage()}";
            }
        }

        return $copied;
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function loyaltyRewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class);
    }

    public function loyaltyRedemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    public function loyaltyNotifications(): HasMany
    {
        return $this->hasMany(LoyaltyNotification::class);
    }

    public function loyaltyPointLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function preOrders(): HasMany
    {
        return $this->hasMany(PreOrder::class);
    }

    public function laundryOrders(): HasMany
    {
        return $this->hasMany(LaundryOrder::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    public function voucherUsages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function trialActivationLogs(): HasMany
    {
        return $this->hasMany(TrialActivationLog::class);
    }

    public function trialNotifications(): HasMany
    {
        return $this->hasMany(TrialNotification::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function isSubscriptionActive(): bool
    {
        if (! $this->is_subscription_active) {
            return false;
        }

        if (! $this->subscription_end) {
            return true;
        }

        return now()->lte($this->subscription_end);
    }

    public function getDaysRemaining(): int
    {
        if (! $this->subscription_end) {
            return 0;
        }

        $days = now()->diffInDays($this->subscription_end, false);

        return max(0, $days);
    }

    public function extendSubscription(int $days): void
    {
        $now = now();

        if (! $this->subscription_start) {
            $this->subscription_start = $now;
        }

        if (! $this->subscription_end || $this->subscription_end->lt($now)) {
            $this->subscription_end = $now->addDays($days);
        } else {
            $this->subscription_end = $this->subscription_end->addDays($days);
        }

        $this->subscription_days += $days;
        $this->is_subscription_active = true;
        $this->save();
    }

    public function reduceSubscription(int $days): void
    {
        if (! $this->subscription_end) {
            return;
        }

        $this->subscription_end = $this->subscription_end->subDays($days);
        $this->subscription_days = max(0, $this->subscription_days - $days);

        if ($this->subscription_end->lt(now())) {
            $this->is_subscription_active = false;
        }

        $this->save();
    }

    /**
     * Set masa aktif awal setelah pembuatan toko: mengikuti paket Starter (bulanan) di database, atau 30 hari jika tidak ada.
     * Menghindari subscription_end kosong yang ditampilkan sebagai "Unlimited" di UI.
     */
    public function applyInitialStarterTrialFromPlans(): void
    {
        $now = now();
        $trialEnd = $now->copy()->addDays(7);

        // Cari paket Bisnis (Pro/GrowUp) untuk trial
        $proPlan = SubscriptionPlan::query()
            ->where('slug', 'bisnis')
            ->where('is_active', true)
            ->first();

        // Fallback ke Starter jika paket Bisnis tidak ada
        $fallbackPlan = SubscriptionPlan::query()
            ->where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();

        $plan = $proPlan ?? $fallbackPlan;

        $this->forceFill([
            'subscription_start' => $this->subscription_start ?? $now,
            'subscription_end' => $trialEnd,
            'subscription_days' => 7,
            'is_subscription_active' => true,
            'subscription_plan_id' => $plan?->id,
            'pro_trial_used' => true,
            'pro_trial_active' => true,
            'pro_trial_start' => $now,
            'pro_trial_end' => $trialEnd,
            'subscription_notes' => $proPlan
                ? 'Registrasi: Trial Pro 7 hari otomatis.'
                : "Registrasi: Trial 7 hari (paket Pro belum tersedia, menggunakan {$fallbackPlan?->name}).",
        ])->save();
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function isCentralWarehouse(): bool
    {
        return StoreLocationType::isCentralWarehouse($this);
    }

    public function isOutlet(): bool
    {
        return StoreLocationType::isOutlet($this);
    }

    public function scopeCentralWarehouse($query)
    {
        return $query->where('location_type', StoreLocationType::CENTRAL_WAREHOUSE);
    }

    public function scopeOutlet($query)
    {
        return $query->where('location_type', StoreLocationType::OUTLET);
    }

    /**
     * Jadikan outlet ini sebagai Outlet Utama di grup usahanya.
     * Hanya satu outlet utama per grup; outlet lain di grup yang sama di-reset.
     */
    public function markAsPrimary(): void
    {
        if ($this->organization_id === null) {
            $this->update(['is_primary' => true]);

            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () {
            static::query()
                ->where('organization_id', $this->organization_id)
                ->where('location_type', StoreLocationType::OUTLET)
                ->update(['is_primary' => false]);

            $this->update(['is_primary' => true]);
        });
    }
}
