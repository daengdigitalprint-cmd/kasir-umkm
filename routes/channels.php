<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('kitchen-store.{storeId}', function ($user, int $storeId): bool {
    if (! $user) {
        return false;
    }

    if ((int) ($user->store_id ?? 0) === $storeId) {
        return true;
    }

    return \Illuminate\Support\Facades\DB::table('store_user')
        ->where('user_id', $user->id)
        ->where('store_id', $storeId)
        ->exists();
});
