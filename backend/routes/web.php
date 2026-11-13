<?php

use App\Http\Controllers\CandidateController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// 画面（要件定義書 第4.4章）。実行（外部 API を呼んで queries に記録する操作）は POST にする

// 質問画面
Route::get('/', [SearchController::class, 'index'])->name('search');
Route::post('/', [SearchController::class, 'run'])->name('search.run');

// 候補者詳細
Route::get('/candidates/{candidate}', [CandidateController::class, 'show'])->whereNumber('candidate')->name('candidates.show');

// 求人票一覧・詳細
Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{job}', [JobController::class, 'show'])->whereNumber('job')->name('jobs.show');
Route::post('/jobs/{job}/match', [JobController::class, 'match'])->whereNumber('job')->name('jobs.match');

// 履歴
Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
Route::post('/history/{queryLog}/rerun', [HistoryController::class, 'rerun'])->whereNumber('queryLog')->name('history.rerun');
