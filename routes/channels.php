<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('capture.{sessionId}', function ($user) {
    return $user !== null;
});

Broadcast::channel('graph.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
