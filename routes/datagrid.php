<?php

use CodeCorner\Datagrid\Http\Controllers\GridController;
use CodeCorner\Datagrid\Http\Middleware\SecureResponse;
use Illuminate\Support\Facades\Route;

Route::prefix(config('datagrid.routes.prefix', 'datagrid'))
    ->middleware(array_merge((array) config('datagrid.routes.middleware', ['api']), [SecureResponse::class]))
    ->group(function () {
        Route::get('{grid}/schema', [GridController::class, 'schema'])->name('datagrid.schema');
        Route::post('{grid}/data', [GridController::class, 'data'])->name('datagrid.data');
        Route::post('{grid}/actions/{action}', [GridController::class, 'action'])->name('datagrid.action');
        Route::post('{grid}/export', [GridController::class, 'export'])->name('datagrid.export');
        Route::post('{grid}/import', [GridController::class, 'import'])->name('datagrid.import');
        Route::get('{grid}/jobs/{job}/download', [GridController::class, 'download'])->name('datagrid.job.download');
        Route::get('{grid}/jobs/{job}', [GridController::class, 'job'])->name('datagrid.job');
        Route::patch('{grid}/rows/{id}', [GridController::class, 'updateCell'])->name('datagrid.cell');
        Route::get('{grid}/filters', [GridController::class, 'filters'])->name('datagrid.filters');
        Route::post('{grid}/filters', [GridController::class, 'storeFilter'])->name('datagrid.filters.store');
        Route::patch('{grid}/filters/{filter}', [GridController::class, 'updateFilter'])->name('datagrid.filters.update');
        Route::delete('{grid}/filters/{filter}', [GridController::class, 'destroyFilter'])->name('datagrid.filters.destroy');
    });
