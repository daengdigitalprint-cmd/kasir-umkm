<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Catat aktivitas ke tabel activity_logs.
     *
     * @param  string  $action  Contoh: 'auth.login', 'product.created', 'transaction.deleted'
     * @param  Model|null  $subject  Model subjek (opsional)
     * @param  array  $metadata  Data tambahan (opsional)
     * @param  User|int|null  $user  User yang melakukan aksi (default: auth user)
     * @param  string|null  $ipAddress  IP address (default: request IP)
     */
    public static function log(
        Store|int $store,
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $metadata = [],
        User|int|null $user = null,
        ?string $ipAddress = null
    ): ActivityLog {
        $storeId = $store instanceof Store ? $store->id : $store;
        $userId = $user instanceof User ? $user->id : $user;

        // Default: user login saat ini
        if ($userId === null && auth()->check()) {
            $userId = auth()->id();
        }

        // Default: IP dari request
        if ($ipAddress === null && request()) {
            $ipAddress = request()->ip();
        }

        return ActivityLog::create([
            'store_id' => $storeId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Catat login user.
     */
    public static function logLogin(User $user, Store $store): ActivityLog
    {
        return self::log(
            store: $store,
            action: 'auth.login',
            description: "User {$user->name} berhasil login",
            user: $user
        );
    }

    /**
     * Catat logout user.
     */
    public static function logLogout(User $user, Store $store): ActivityLog
    {
        return self::log(
            store: $store,
            action: 'auth.logout',
            description: "User {$user->name} logout",
            user: $user
        );
    }

    /**
     * Catat pembuatan entitas.
     */
    public static function logCreated(Store|int $store, Model $subject, ?string $description = null, array $metadata = []): ActivityLog
    {
        $entityName = class_basename($subject);

        return self::log(
            store: $store,
            action: strtolower($entityName).'.created',
            description: $description ?? "Membuat {$entityName} baru",
            subject: $subject,
            metadata: $metadata
        );
    }

    /**
     * Catat pembaruan entitas.
     */
    public static function logUpdated(Store|int $store, Model $subject, ?string $description = null, array $metadata = []): ActivityLog
    {
        $entityName = class_basename($subject);

        return self::log(
            store: $store,
            action: strtolower($entityName).'.updated',
            description: $description ?? "Memperbarui {$entityName}",
            subject: $subject,
            metadata: $metadata
        );
    }

    /**
     * Catat penghapusan entitas.
     */
    public static function logDeleted(Store|int $store, Model $subject, ?string $description = null, array $metadata = []): ActivityLog
    {
        $entityName = class_basename($subject);

        return self::log(
            store: $store,
            action: strtolower($entityName).'.deleted',
            description: $description ?? "Menghapus {$entityName}",
            subject: $subject,
            metadata: $metadata
        );
    }
}
