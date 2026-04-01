<?php

use App\Http\Controllers\CaptureController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function (): void {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');
        Route::inertia('capture', 'Capture')->name('capture');
    });

Route::middleware(['auth'])->group(function (): void {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::post('api/capture/text', [CaptureController::class, 'text'])->name('capture.text');
    Route::post('api/capture/audio', [CaptureController::class, 'audio'])->name('capture.audio');
});

require __DIR__.'/settings.php';
