<?php

use App\Http\Controllers\Preview\WatchlineMailPreviewController;
use Illuminate\Support\Facades\Route;

// Runtime guards remain effective even when the production routes are cached.
Route::get('preview/watchline-mail', [WatchlineMailPreviewController::class, 'index'])->name('preview.watchline-mail.index');
Route::get('preview/watchline-mail/{key}', [WatchlineMailPreviewController::class, 'show'])
    ->where('key', '[a-z-]+')->name('preview.watchline-mail.show');
