<?php

use App\Http\Controllers\EntryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScrapeController;

 
Route::get('/scrape', [ScrapeController::class, 'triggerScrape']);
Route::get('/status', [ScrapeController::class, 'checkStatus']);
Route::get('/data', [ScrapeController::class, 'getData']);

Route::apiResource('entries', EntryController::class);