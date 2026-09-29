<?php

use App\Http\Controllers\ProductSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProductSearchController::class, 'index'])->name('products.search');

Route::get('/api/products', [ProductSearchController::class, 'api'])
    ->middleware('throttle:60,1')
    ->name('products.api');
