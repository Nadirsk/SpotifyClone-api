<?php

use App\Http\Controllers\DiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Deliberately outside api/v1: this is an HTML page for a person to load in
 | a browser, not a JSON endpoint, and it must survive even if the API's own
 | middleware stack (ForceJsonResponse, Sanctum, CORS) is misbehaving - it is
 | as much a check on the app being up at all as it is on Reverb/the
 | scheduler specifically. See DiagnosticsController for the key gate.
 */
Route::get('/diagnostics', [DiagnosticsController::class, 'show'])->name('diagnostics');
