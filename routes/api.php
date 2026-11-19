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

// Tarifas interurbanas de taxi por comunidad autónoma (referencia para el precio/km)
Route::get('/reference/taxi-tariffs', [\App\Http\Controllers\TaxiTariffController::class, 'index']);

// Quién invita (enlace /i/{código}), para que la app lo muestre antes de unirse.
Route::get('/invitations/{code}', [\App\Http\Controllers\InvitationLinkController::class, 'info'])
    ->middleware('throttle:30,1')
    ->where('code', '[A-Za-z0-9]{8}');

// Rutas protegidas con Sanctum (owner/driver/admin)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'userProfile']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::patch('/user/password', [AuthController::class, 'updatePassword']);
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
    Route::patch('/user/avatar-color', [AuthController::class, 'updateAvatarColor']);
    Route::patch('/user/preferences', [AuthController::class, 'updatePreferences']);
    // Resumen / analytics / export propios; un admin puede pedir el de otro con ?user_id=.
    Route::get('/reports/user-summary', [\App\Http\Controllers\ReportController::class, 'userSummary']);
    Route::get('/reports/user-analytics', [\App\Http\Controllers\ReportController::class, 'userAnalytics']);
    Route::get('/reports/user-export', [\App\Http\Controllers\ReportController::class, 'userExport']);
    Route::get('/geocode/search', [\App\Http\Controllers\GeocodingController::class, 'search']);
    Route::get('/geocode/distance', [\App\Http\Controllers\GeocodingController::class, 'distance']);
    // Protected ruta actions: show, update, destroy
    // Listar y crear también exigen sesión: sin ella, el listado devolvía los
    // viajes de todos los usuarios (con nombre y teléfono del cliente).
    Route::apiResource('rutas', RutaController::class);
    // Marcar como revisado un cierre automático ("Por revisar" → "Correcto")
    Route::post('/rutas/{ruta}/review', [RutaController::class, 'review']);
    // Restaurar un viaje eliminado (borrado blando)
    Route::post('/rutas/{ruta}/restore', [RutaController::class, 'restore'])->withTrashed();
    // Vehicles CRUD
    Route::apiResource('vehicles', \App\Http\Controllers\VehicleController::class);

    // Owner monthly codes
    Route::get('/owner/codes', [\App\Http\Controllers\OwnerCodeController::class, 'index']);
    Route::post('/owner/codes', [\App\Http\Controllers\OwnerCodeController::class, 'store']);
    Route::delete('/owner/codes/{ownerMonthCode}', [\App\Http\Controllers\OwnerCodeController::class, 'destroy']);

    // Equipo: propietario y sus conductores (vínculo persistente con consentimiento)
    Route::get('/team', [\App\Http\Controllers\TeamController::class, 'index']);
    Route::post('/team/invitations', [\App\Http\Controllers\TeamController::class, 'invite'])->middleware('throttle:10,1');
    Route::patch('/team/{link}', [\App\Http\Controllers\TeamController::class, 'update']);
    Route::delete('/team/{link}', [\App\Http\Controllers\TeamController::class, 'destroy']);
    Route::get('/me/invitations', [\App\Http\Controllers\TeamController::class, 'myInvitations']);
    Route::post('/me/invitations/{link}/accept', [\App\Http\Controllers\TeamController::class, 'accept']);
    Route::post('/me/invitations/{link}/reject', [\App\Http\Controllers\TeamController::class, 'reject']);
    Route::get('/me/owners', [\App\Http\Controllers\TeamController::class, 'myOwners']);

    // Jornadas del propietario (varias por día, con conductor, vehículo y código)
    Route::get('/jornadas', [\App\Http\Controllers\JornadaController::class, 'index']);
    Route::post('/jornadas', [\App\Http\Controllers\JornadaController::class, 'store']);
    Route::patch('/jornadas/{jornada}', [\App\Http\Controllers\JornadaController::class, 'update']);
    Route::delete('/jornadas/{jornada}', [\App\Http\Controllers\JornadaController::class, 'destroy']);
    Route::post('/jornadas/{jornada}/code', [\App\Http\Controllers\JornadaController::class, 'regenerateCode']);

    // Gastos del propietario (pantalla Gastos e informes de beneficio)
    Route::get('/reference/expense-categories', [\App\Http\Controllers\ExpenseController::class, 'categories']);
    Route::get('/expenses', [\App\Http\Controllers\ExpenseController::class, 'index']);
    Route::post('/expenses', [\App\Http\Controllers\ExpenseController::class, 'store']);
    Route::patch('/expenses/{expense}', [\App\Http\Controllers\ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [\App\Http\Controllers\ExpenseController::class, 'destroy']);
    Route::post('/expenses/{id}/restore', [\App\Http\Controllers\ExpenseController::class, 'restore'])->whereNumber('id');

    // Facturación: datos fiscales del emisor, facturas de los viajes y destinatarios
    Route::get('/billing-profile', [\App\Http\Controllers\BillingProfileController::class, 'show']);
    Route::put('/billing-profile', [\App\Http\Controllers\BillingProfileController::class, 'update']);
    Route::put('/billing-profile/logo', [\App\Http\Controllers\BillingProfileController::class, 'updateLogo']);
    Route::delete('/billing-profile/logo', [\App\Http\Controllers\BillingProfileController::class, 'deleteLogo']);
    Route::get('/rutas/{ruta}/invoice', [\App\Http\Controllers\InvoiceController::class, 'forRuta'])->withTrashed();
    Route::post('/rutas/{ruta}/invoice', [\App\Http\Controllers\InvoiceController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}/pdf', [\App\Http\Controllers\InvoiceController::class, 'pdf']);
    Route::post('/invoices/{invoice}/corrective', [\App\Http\Controllers\InvoiceController::class, 'corrective']);
    Route::get('/invoice-clients', [\App\Http\Controllers\InvoiceController::class, 'clients']);

    // Drivers joining codes
    Route::post('/driver/join-code', [\App\Http\Controllers\DriverCodeController::class, 'join'])->middleware('throttle:10,1');
    Route::get('/driver/my-codes', [\App\Http\Controllers\DriverCodeController::class, 'listMyCodes']);

    // Admin oversight
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [\App\Http\Controllers\AdminController::class, 'users']);
        Route::patch('/users/{user}', [\App\Http\Controllers\AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [\App\Http\Controllers\AdminController::class, 'deleteUser']);
        Route::get('/reports/summary', [\App\Http\Controllers\AdminController::class, 'reportsSummary']);
    });
});
