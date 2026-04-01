<?php

use App\Http\Controllers\CaptureController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('capture/text', [CaptureController::class, 'text'])->name('capture.text');
    Route::post('capture/audio', [CaptureController::class, 'audio'])->name('capture.audio');
});
