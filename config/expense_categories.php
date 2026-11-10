<?php

/*
 * Categorías de gasto del propietario (pantalla Gastos). Se sirven en
 * GET /api/reference/expense-categories; para añadir una basta con
 * incluirla aquí. "links" indica qué datos pide el formulario:
 *  - vehicle: el vehículo al que se imputa (opcional).
 *  - driver: el conductor del equipo (obligatorio en nóminas).
 * "other" es el gasto genérico: su descripción la escribe el usuario.
 */
return [
    'payroll' => ['es' => 'Nóminas de conductores', 'en' => 'Driver payroll', 'icon' => 'account-cash-outline', 'links' => ['driver'], 'driver_required' => true],
    'fuel' => ['es' => 'Combustible / carga', 'en' => 'Fuel / charging', 'icon' => 'gas-station-outline', 'links' => ['vehicle']],
    'workshop' => ['es' => 'Taller y mantenimiento', 'en' => 'Workshop & maintenance', 'icon' => 'wrench-outline', 'links' => ['vehicle']],
    'fines' => ['es' => 'Multas y sanciones', 'en' => 'Fines & penalties', 'icon' => 'alert-octagon-outline', 'links' => ['vehicle', 'driver']],
    'insurance' => ['es' => 'Seguros', 'en' => 'Insurance', 'icon' => 'shield-car', 'links' => ['vehicle']],
    'tolls_parking' => ['es' => 'Peajes y aparcamiento', 'en' => 'Tolls & parking', 'icon' => 'boom-gate-outline', 'links' => ['vehicle']],
    'taxes_fees' => ['es' => 'Impuestos, cuota de autónomos y licencias', 'en' => 'Taxes, fees & licences', 'icon' => 'bank-outline', 'links' => []],
    'cleaning' => ['es' => 'Limpieza', 'en' => 'Cleaning', 'icon' => 'spray-bottle', 'links' => ['vehicle']],
    'other' => ['es' => 'Otro', 'en' => 'Other', 'icon' => 'dots-horizontal-circle-outline', 'links' => ['vehicle'], 'description_required' => true],
];
