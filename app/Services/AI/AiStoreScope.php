<?php

namespace App\Services\AI;

use App\Models\Store;
use App\Models\User;

/**
 * Menentukan store_id mana yang boleh di-query oleh tool laporan AI,
 * mendukung agregasi lintas cabang (multi-outlet) untuk usaha multi-cabang.
 */
class AiStoreScope
{
    public const ALL = 'all';

    public const CURRENT = 'current';

    /**
     * Resolusi ID outlet yang boleh di-query untuk permintaan scope tertentu.
     *
     * Scope 'all' hanya berlaku untuk role back-office (owner/admin/manager/superadmin)
     * pada grup usaha (organization) yang sama; selain itu fallback ke outlet aktif saja.
     *
     * @return array{store_ids: array<int, int>, all_branches: bool}
     */
    public static function resolve(User $user, mixed $requestedScope): array
    {
        $currentStore = $user->store;

        if (! $currentStore) {
            return ['store_ids' => [], 'all_branches' => false];
        }

        $isAll = strtolower((string) $requestedScope) === self::ALL;

        if (! $isAll) {
            return ['store_ids' => [$currentStore->id], 'all_branches' => false];
        }

        if (! $currentStore->organization_id || ! $user->canAccessBackOffice()) {
            return ['store_ids' => [$currentStore->id], 'all_branches' => false];
        }

        $storeIds = Store::query()
            ->where('organization_id', $currentStore->organization_id)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! in_array($currentStore->id, $storeIds, true)) {
            $storeIds[] = (int) $currentStore->id;
        }

        return ['store_ids' => $storeIds, 'all_branches' => true];
    }
}
