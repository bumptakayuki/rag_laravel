<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// 質問画面（要件定義書 第4.4章）。RS-004b では検索部分だけ
Route::get('/', [SearchController::class, 'index'])->name('search');
