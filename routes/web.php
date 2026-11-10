<?php

use Illuminate\Support\Facades\Route;

// Simple root route used by tests
Route::get('/', function () {
	return response('OK', 200);
});

// Enlace de invitación al equipo: abre la app o permite descargarla.
Route::get('/i/{code}', [\App\Http\Controllers\InvitationLinkController::class, 'show'])
    ->middleware('throttle:30,1')
    ->where('code', '[A-Za-z0-9]{8}');
