<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.notifications', function ($user) {
    return (bool) ($user->is_super_admin ?? false);
});
