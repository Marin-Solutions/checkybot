<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// SEO Check progress channel - public channel for progress updates
// This is safe because we're only broadcasting progress data, not sensitive information
Broadcast::channel('seo-checks.{seoCheckId}', function () {
    return true; // Allow public access to SEO check progress updates
});
