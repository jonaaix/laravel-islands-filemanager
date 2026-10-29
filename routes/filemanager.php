<?php

declare(strict_types=1);

use Aaix\LaravelIslandsFilemanager\Http\FileManagerController as Controller;
use Illuminate\Support\Facades\Route;

Route::get('contents', [Controller::class, 'contents'])->name('contents');
Route::get('search', [Controller::class, 'search'])->name('search');
Route::put('view', [Controller::class, 'saveView'])->name('view');
Route::put('preferences', [Controller::class, 'savePreferences'])->name('preferences');

Route::get('folders', [Controller::class, 'folders'])->name('folders.index');
Route::post('folders', [Controller::class, 'storeFolder'])->name('folders.store');
Route::post('folders/with-items', [Controller::class, 'storeFolderWithItems'])->name('folders.store-with-items');
Route::put('folders/{folder}', [Controller::class, 'renameFolder'])->name('folders.rename');

Route::put('items/move', [Controller::class, 'moveItems'])->name('items.move');
Route::post('items/copy', [Controller::class, 'copyItems'])->name('items.copy');
Route::delete('items', [Controller::class, 'destroyItems'])->name('items.destroy');
Route::get('items/zip', [Controller::class, 'zip'])->name('items.zip');

Route::post('files', [Controller::class, 'storeFiles'])->name('files.store');
Route::get('files/{file}', [Controller::class, 'showFile'])->name('files.show');
Route::put('files/{file}', [Controller::class, 'updateFile'])->name('files.update');
Route::get('files/{file}/content', [Controller::class, 'content'])->name('files.content');
Route::get('files/{file}/thumbnail', [Controller::class, 'thumbnail'])->name('files.thumbnail');

Route::post('files/{file}/comments', [Controller::class, 'storeComment'])->name('comments.store');
Route::delete('comments/{comment}', [Controller::class, 'destroyComment'])->name('comments.destroy');
