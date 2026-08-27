<?php

declare(strict_types=1);

use App\Http\Controllers\Media\FilesController;
use App\Http\Controllers\Media\FoldersController;
use App\Http\Controllers\Media\PickerController;
use App\Http\Controllers\Media\ReferencesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Media REST endpoints
|--------------------------------------------------------------------------
| Mounted under web middleware so they share the staff session and tenant
| context with the Filament admin and the Vue builder. Authorization is
| enforced per-route by media_view / media_manage permissions and per-row
| by MediaFilePolicy / MediaFolderPolicy.
*/

Route::middleware(['auth', 'tenant', 'verified'])->prefix('media')->name('media.')->group(function (): void {
    Route::get('folders', [FoldersController::class, 'index'])->name('folders.index');
    Route::get('folders/{folder}', [FoldersController::class, 'show'])->name('folders.show');
    Route::post('folders', [FoldersController::class, 'store'])->name('folders.store');
    Route::patch('folders/{folder}', [FoldersController::class, 'update'])->name('folders.update');
    Route::delete('folders/{folder}', [FoldersController::class, 'destroy'])->name('folders.destroy');

    Route::get('files', [FilesController::class, 'index'])->name('files.index');
    Route::get('files/{file}', [FilesController::class, 'show'])->withTrashed()->name('files.show');
    Route::post('files', [FilesController::class, 'store'])->name('files.store');
    Route::patch('files/{file}', [FilesController::class, 'update'])->name('files.update');
    Route::delete('files/{file}', [FilesController::class, 'destroy'])->name('files.destroy');
    Route::post('files/{file}/restore', [FilesController::class, 'restore'])->withTrashed()->name('files.restore');
    Route::delete('files/{file}/force', [FilesController::class, 'forceDestroy'])->withTrashed()->name('files.force');

    Route::get('files/{file}/references', [ReferencesController::class, 'index'])->name('files.references');

    Route::get('picker/contents', [PickerController::class, 'contents'])->name('picker.contents');

    // Signed download — checked via signed middleware, not auth, so signed URLs work
    // from contexts where the session cookie may not travel (e.g. img src).
    Route::get('files/{file}/raw', [FilesController::class, 'raw'])
        ->withTrashed()
        ->withoutMiddleware(['auth', 'verified'])
        ->middleware('signed')
        ->name('files.raw');
});
