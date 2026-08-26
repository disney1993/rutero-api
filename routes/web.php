<?php

use Illuminate\Support\Facades\Route;

// Simple root route used by tests
Route::get('/', function () {
	return response('OK', 200);
});

