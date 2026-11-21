<?php

use Illuminate\Support\Facades\Route;
use Passorde\Http\Controllers\LicenceController;

Route::get('licence.css', [LicenceController::class, 'stylesheet'])->name('passorde.licence.css');
Route::get('licence', [LicenceController::class, 'show'])->name('passorde.licence.show');
Route::post('licence', [LicenceController::class, 'install'])->name('passorde.licence.install');
Route::post('licence/refresh', [LicenceController::class, 'refresh'])->name('passorde.licence.refresh');
