<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RutaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Rutas públicas
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Login con Google
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/auth/google/mobile', [AuthController::class, 'mobileGoogle']);

// Catálogo estático de referencia (marcas/modelos de coches)
Route::get('/car-catalog/makes', [\App\Http\Controllers\CarCatalogController::class, 'makes']);
Route::get('/car-catalog/models', [\App\Http\Controllers\CarCatalogController::class, 'models']);

// Rutas protegidas con Sanctum (owner/driver/admin)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'userProfile']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::patch('/user/password', [AuthController::class, 'updatePassword']);
    Route::patch('/user/avatar-color', [AuthController::class, 'updateAvatarColor']);
    Route::patch('/user/preferences', [AuthController::class, 'updatePreferences']);
    Route::get('/geocode/search', [\App\Http\Controllers\GeocodingController::class, 'search']);
    Route::get('/geocode/distance', [\App\Http\Controllers\GeocodingController::class, 'distance']);
    Route::get('/geocode/status', [\App\Http\Controllers\GeocodingController::class, 'status']);
    // Full rutas CRUD requires authentication (index/store were previously public).
    Route::apiResource('rutas', RutaController::class);
    // Vehicles CRUD
    Route::apiResource('vehicles', \App\Http\Controllers\VehicleController::class);
    Route::patch('/vehicles/{vehicle}/default', [\App\Http\Controllers\VehicleController::class, 'setDefault']);

    // Jornadas (day shifts with join codes)
    Route::get('/jornadas', [\App\Http\Controllers\JornadaController::class, 'index']);
    Route::post('/jornadas', [\App\Http\Controllers\JornadaController::class, 'store']);
    Route::post('/jornadas/join', [\App\Http\Controllers\JornadaController::class, 'join']);
    Route::get('/driver/jornadas', [\App\Http\Controllers\JornadaController::class, 'myJoined']);
    Route::get('/jornadas/{jornada}', [\App\Http\Controllers\JornadaController::class, 'show']);
    Route::patch('/jornadas/{jornada}', [\App\Http\Controllers\JornadaController::class, 'update']);
    Route::delete('/jornadas/{jornada}', [\App\Http\Controllers\JornadaController::class, 'destroy']);
    Route::get('/jornadas/{jornada}/vehicles', [\App\Http\Controllers\JornadaController::class, 'vehicles']);

    // Owner monthly codes (legacy — prefer /jornadas)
    Route::get('/owner/codes', [\App\Http\Controllers\OwnerCodeController::class, 'index']);
    Route::post('/owner/codes', [\App\Http\Controllers\OwnerCodeController::class, 'store']);
    Route::delete('/owner/codes/{ownerMonthCode}', [\App\Http\Controllers\OwnerCodeController::class, 'destroy']);

    // Drivers joining monthly codes (legacy — prefer /jornadas/join)
    Route::post('/driver/join-code', [\App\Http\Controllers\DriverCodeController::class, 'join']);
    Route::get('/driver/my-codes', [\App\Http\Controllers\DriverCodeController::class, 'listMyCodes']);

    // Admin oversight
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users']);
        Route::patch('/users/{user}', [\App\Http\Controllers\AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'deleteUser']);
        Route::get('/reports/summary', [\App\Http\Controllers\AdminController::class, 'reportsSummary']);
        Route::get('/audits', [\App\Http\Controllers\AuditController::class, 'index']);
        Route::get('/rutas/{ruta}/audits', [\App\Http\Controllers\AuditController::class, 'forRuta']);
        Route::get('/vehicles/{vehicle}/audits', [\App\Http\Controllers\AuditController::class, 'forVehicle']);
    });
});
