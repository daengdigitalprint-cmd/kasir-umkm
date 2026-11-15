<?php

namespace App\Http\Controllers;

use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\MenuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccessController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            $store = Store::query()->whereKey($validated['store_id'])->firstOrFail();
            $user->update(['store_id' => $store->id]);

            return back()->with('success', 'Toko aktif dipilih: '.$store->name);
        }

        $target = Store::query()->whereKey($validated['store_id'])->firstOrFail();
        if (! $target->is_active) {
            return back()->with('error', 'Toko tersebut dinonaktifkan.');
        }

        $membership = $user->stores()->where('stores.id', $validated['store_id'])->first();
        if (! $membership) {
            return back()->with('error', 'Anda tidak memiliki akses ke toko tersebut.');
        }

        $pivotRole = $membership->pivot->role ?? $user->role;

        $user->update([
            'store_id' => (int) $validated['store_id'],
            'role' => $pivotRole,
        ]);

        return back()->with('success', 'Toko aktif: '.$membership->name);
    }

    public function attachMember(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:admin,manager,cashier,kitchen,warehouse,owner,barber'],
        ]);

        $actor = $request->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        // Admin/super_admin bisa menugaskan role owner (canManageStoreSettings sudah memvalidasi)
        if ($validated['role'] === 'owner' && ! $actor->isSuperAdmin() && ! $actor->isAdmin()) {
            return back()->with('error', 'Hanya admin atau pemilik toko yang dapat menugaskan role owner.');
        }

        if (! $actor->isMemberOfStore($store)) {
            return back()->with('error', 'Anda bukan anggota toko ini.');
        }

        $member = User::query()->where('email', $validated['email'])->first();
        if (! $member) {
            return back()->with('error', 'Pengguna dengan email itu belum terdaftar. Minta mereka mendaftar dulu.');
        }

        if ($member->isSuperAdmin()) {
            return back()->with('error', 'Tidak dapat menambahkan akun super admin ke toko.');
        }

        $role = $validated['role'];

        $store->members()->syncWithoutDetaching([
            $member->id => ['role' => $role],
        ]);

        if ($member->store_id === null) {
            $member->update([
                'store_id' => $store->id,
                'role' => $role,
            ]);
        }

        // Owner otomatis mendapat akses ke semua outlet dalam satu grup usaha
        if ($role === 'owner') {
            $member->attachAsOwnerToAllStoresInOrganization($store);
        }

        // Log aktivitas
        \App\Services\ActivityLogger::log(
            store: $store,
            action: 'member.added',
            description: "Menambahkan {$member->name} sebagai {$role}",
            metadata: [
                'member_email' => $member->email,
                'member_name' => $member->name,
                'role' => $role,
            ]
        );

        return back()->with('success', 'Anggota ditambahkan: '.$member->email);
    }

    /**
     * Buat akun pengguna baru dan lampirkan ke toko (nama, email, kata sandi, peran).
     */
    public function createMember(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:admin,manager,cashier,kitchen,warehouse,owner,barber'],
        ]);

        $actor = $request->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        // Admin/super_admin bisa menugaskan role owner
        if ($validated['role'] === 'owner' && ! $actor->isSuperAdmin() && ! $actor->isAdmin()) {
            return back()->with('error', 'Hanya admin atau pemilik toko yang dapat menugaskan role owner.');
        }

        if (! $actor->isMemberOfStore($store)) {
            return back()->with('error', 'Anda bukan anggota toko ini.');
        }

        $member = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'business_type' => $store->business_type ?? 'general',
            'store_id' => $store->id,
            'role' => $validated['role'] === 'admin' ? 'admin' : 'cashier',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $store->members()->syncWithoutDetaching([
            $member->id => ['role' => $validated['role']],
        ]);

        // Owner otomatis mendapat akses ke semua outlet dalam satu grup usaha
        if ($validated['role'] === 'owner') {
            $member->attachAsOwnerToAllStoresInOrganization($store);
        }

        // Log aktivitas
        \App\Services\ActivityLogger::log(
            store: $store,
            action: 'member.account_created',
            description: "Membuat akun baru untuk {$member->name} dengan role {$validated['role']}",
            metadata: [
                'member_email' => $member->email,
                'member_name' => $member->name,
                'role' => $validated['role'],
            ]
        );

        return back()->with('success', 'Akun anggota dibuat. Bagikan email dan kata sandi kepada pengguna: '.$member->email);
    }

    public function updateMember(Request $request, Store $store, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:admin,manager,cashier,kitchen,warehouse,owner,barber'],
        ]);

        $actor = $request->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        if (! $actor->isMemberOfStore($store)) {
            return back()->with('error', 'Anda bukan anggota toko ini.');
        }

        if (! $store->members()->whereKey($user->id)->exists()) {
            return back()->with('error', 'Pengguna bukan anggota toko ini.');
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Tidak dapat mengubah akun super admin.');
        }

        // Admin/super_admin bisa menugaskan/mengubah role owner
        if ($validated['role'] === 'owner' && ! $actor->isSuperAdmin() && ! $actor->isAdmin()) {
            return back()->with('error', 'Hanya admin atau pemilik toko yang dapat menugaskan role owner.');
        }

        $newRole = $validated['role'];
        $memberRow = $store->members()->where('users.id', $user->id)->first();
        $currentRole = $memberRow?->pivot->role;

        if ($currentRole === 'admin' && $newRole !== 'admin') {
            $adminCount = $store->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return back()->with('error', 'Toko harus memiliki minimal satu admin.');
            }
        }

        $data = [];
        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
        }
        if (array_key_exists('email', $validated)) {
            $data['email'] = $validated['email'];
        }
        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }
        if ($data !== []) {
            $user->update($data);
        }

        $store->members()->updateExistingPivot($user->id, ['role' => $newRole]);

        if ((int) $user->store_id === (int) $store->id) {
            $user->update(['role' => $newRole]);
        }

        // Jika dijadikan owner, otomatis mendapat akses ke semua outlet dalam satu grup usaha
        if ($newRole === 'owner') {
            $user->attachAsOwnerToAllStoresInOrganization($store);
        }

        // Log aktivitas
        \App\Services\ActivityLogger::log(
            store: $store,
            action: 'member.updated',
            description: "Memperbarui data {$user->name}",
            metadata: [
                'member_email' => $user->email,
                'member_name' => $user->name,
                'old_role' => $currentRole,
                'new_role' => $newRole,
            ]
        );

        return back()->with('success', 'Data anggota diperbarui untuk '.$user->email);
    }

    public function removeMember(Request $request, Store $store, User $user): RedirectResponse
    {
        $actor = $request->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        if (! $actor->isMemberOfStore($store)) {
            return back()->with('error', 'Anda bukan anggota toko ini.');
        }

        if (! $store->members()->whereKey($user->id)->exists()) {
            return back()->with('error', 'Pengguna bukan anggota toko ini.');
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Tidak dapat menghapus akun super admin.');
        }

        if ((int) $actor->id === (int) $user->id) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri dari toko.');
        }

        $currentRole = $store->members()->where('users.id', $user->id)->first()?->pivot->role;
        if ($currentRole === 'admin') {
            $adminCount = $store->members()->wherePivot('role', 'admin')->count();
            if ($adminCount <= 1) {
                return back()->with('error', 'Toko harus memiliki minimal satu admin.');
            }
        }

        $store->members()->detach($user->id);

        if ((int) $user->store_id === (int) $store->id) {
            $nextStore = $user->stores()->where('stores.id', '!=', $store->id)->first();
            $user->update([
                'store_id' => $nextStore?->id,
                'role' => $nextStore?->pivot?->role ?? $user->role,
            ]);
        }

        \App\Services\ActivityLogger::log(
            store: $store,
            action: 'member.removed',
            description: "Menghapus {$user->name} dari toko",
            metadata: [
                'member_email' => $user->email,
                'member_name' => $user->name,
                'role' => $currentRole,
            ]
        );

        return back()->with('success', 'Anggota dihapus: '.$user->email);
    }

    private const PERMISSION_ROLES = ['admin', 'manager', 'cashier', 'kitchen', 'warehouse', 'owner', 'barber'];

    /** Daftar menu dan hak akses per role untuk toko aktif. */
    public function menuPermissions()
    {
        $actor = request()->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        $store = $actor->store;

        $permissions = [];
        $customized = [];
        foreach (self::PERMISSION_ROLES as $role) {
            $permissions[$role] = RoleMenuPermission::allowedMenus($role, $store);
            $customized[$role] = RoleMenuPermission::storeOverride($role, $store) !== null;
        }

        return response()->json([
            'roles' => self::PERMISSION_ROLES,
            'menus' => MenuCatalog::ITEMS,
            'all_menus' => MenuCatalog::keys(),
            'permissions' => $permissions,
            'customized' => $customized,
        ]);
    }

    /**
     * Simpan hak akses menu satu role untuk toko aktif saja (di stores.settings).
     * Default sistem di tabel role_menu_permissions dipakai bersama semua toko,
     * sehingga tidak boleh diubah dari halaman toko.
     */
    public function updateMenuPermissions(Request $request)
    {
        $actor = $request->user();
        if (! $actor?->canManageStoreSettings()) {
            abort(403);
        }

        $store = $actor->store;
        if (! $store) {
            abort(422, 'Tidak ada toko aktif.');
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::PERMISSION_ROLES)],
            'menus' => ['present', 'array'],
            'menus.*' => ['required', 'string', 'max:64'],
        ]);

        $role = $validated['role'];

        // Menu di luar daftar (mis. menu khusus admin atau modul yang sedang mati)
        // tidak tampil di form, jadi nilainya dipertahankan dari pengaturan
        // sebelumnya agar tidak hilang diam-diam.
        $editable = MenuCatalog::keys();
        $chosen = array_values(array_intersect($editable, $validated['menus']));
        $kept = array_values(array_diff(RoleMenuPermission::allowedMenus($role, $store), $editable));
        $menus = array_values(array_unique([...$chosen, ...$kept]));

        $settings = $store->settings ?? [];
        $settings[RoleMenuPermission::STORE_SETTINGS_KEY][$role] = $menus;
        $store->settings = $settings;
        $store->save();

        ActivityLogger::log(
            store: $store,
            action: 'menu_permissions.updated',
            description: "Mengubah hak akses menu peran {$role}",
            metadata: ['role' => $role, 'menus' => $menus],
        );

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'role' => $role, 'menus' => $menus]);
        }

        return back()->with('success', 'Hak akses menu untuk role '.$role.' berhasil diperbarui.');
    }
}
