<?php

use App\Models\Admin;
use Illuminate\Support\Facades\Broadcast;

// 本系统所有用户均为 Admin（admin guard），默认 web guard 下该频道永远无法授权。
// 显式绑定 admin guard，避免死频道/误开放。
Broadcast::channel('App.Models.User.{id}', function (Admin $admin, $id) {
    return (int) $admin->id === (int) $id;
}, ['guards' => ['admin']]);

Broadcast::channel('admin.tasks', function (Admin $admin): bool {
    return (string) ($admin->status ?? '') === 'active';
}, ['guards' => ['admin']]);
