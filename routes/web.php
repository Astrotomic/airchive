<?php

use App\Http\Controllers\Auth\DevicePairingController;
use App\Http\Controllers\Auth\RegisterPasskeyController;
use App\Http\Controllers\Auth\ShowPasskeyEnrollmentController;
use App\Http\Controllers\Auth\VerifyPasskeyEnrollmentController;
use App\Http\Controllers\BulkConversationExportController;
use App\Http\Controllers\ConversationExportController;
use App\Http\Controllers\DownloadAttachmentController;
use App\Http\Controllers\PreviewAttachmentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/conversations');

Route::middleware(['guest', 'signed', 'throttle:enrollment'])->group(function () {
    Route::get('/enroll/{user}', ShowPasskeyEnrollmentController::class)->name('enroll.show');
    Route::post('/enroll/{user}', VerifyPasskeyEnrollmentController::class)->name('enroll.verify');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/enroll/register-passkey', RegisterPasskeyController::class)->name('enroll.register');
});

Route::middleware(['auth', 'mfa.verified'])->group(function () {
    Route::livewire('/conversations', 'conversation-index')->name('conversations.index');
    Route::livewire('/conversations/search', 'conversation-search')->name('conversations.search');
    Route::livewire('/conversations/{conversation}', 'conversation-show')->name('conversations.show');
    Route::get('/conversations/{conversation}/export', ConversationExportController::class)
        ->middleware('can:export,conversation')
        ->name('conversations.export');
    Route::livewire('/projects', 'project-index')->name('projects.index');
    Route::livewire('/projects/{project}', 'project-show')->name('projects.show');
    Route::livewire('/exports', 'exports.index')->name('exports.index');
    Route::post('/exports/download', BulkConversationExportController::class)->name('exports.download');
    Route::livewire('/library', 'library.index')->name('library.index');
    Route::get('/library/{attachment}/preview', PreviewAttachmentController::class)
        ->middleware('can:view,attachment')
        ->name('library.preview');
    Route::get('/library/{attachment}/download', DownloadAttachmentController::class)
        ->middleware('can:view,attachment')
        ->name('library.download');
    Route::livewire('/imports', 'imports.upload')->name('imports.upload');
    Route::livewire('/account', 'account-settings')->name('account.settings');

    Route::post('/account/devices/pair', DevicePairingController::class)->name('account.devices.pair');
});
