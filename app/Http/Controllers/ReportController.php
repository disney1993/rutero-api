<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly UserAnalyticsService $analytics
    ) {}

    // Resumen de actividad de UN usuario. Un admin puede pedir el de
    // cualquiera pasando user_id; cualquier otro usuario solo puede ver el
    // suyo propio (igual que la atribución de owner_id en RutaController).
    public function userSummary(Request $request)
    {
        $request->validate([
            'user_id' => 'sometimes|integer|exists:users,id',
            'role' => 'sometimes|in:owner,driver,all',
        ]);

        $target = $this->resolveTargetUser($request, forbidForeignUserId: false);
        $role = $this->resolveAnalyticsRole($request, $target);
        $lifetime = $this->analytics->lifetimeSummary($target->id, $role);

        return response()->json([
            'user' => $target->only(['id', 'first_name', 'last_name', 'email', 'avatar_color']),
            'role' => $role,
            'vehicles_total' => $role === 'driver' ? 0 : $target->vehicles()->count(),
            'rutas_total' => $lifetime['rutas_total'],
            'has_owner_capability' => $target->hasOwnerCapability(),
            'has_driver_capability' => $target->hasDriverCapability(),
            'rutas_by_status' => $lifetime['rutas_by_status'],
            'revenue_completed' => $lifetime['revenue_completed'],
        ]);
    }

    public function userAnalytics(Request $request)
    {
        $request->validate([
            'period' => 'required|in:day,month,year',
            'date' => 'required|date_format:Y-m-d',
            'user_id' => 'sometimes|integer|exists:users,id',
            'role' => 'sometimes|in:owner,driver,all',
        ]);

        $target = $this->resolveTargetUser($request, forbidForeignUserId: true);
        $role = $this->resolveAnalyticsRole($request, $target);
        $anchor = CarbonImmutable::createFromFormat('Y-m-d', $request->input('date'))->startOfDay();

        return response()->json(
            $this->analytics->analyticsFor($target, $request->input('period'), $anchor, $role)
        );
    }

    public function userExport(Request $request)
    {
        $request->validate([
            'period' => 'required|in:day,month,year',
            'date' => 'required|date_format:Y-m-d',
            'format' => 'required|in:xlsx,pdf',
            'user_id' => 'sometimes|integer|exists:users,id',
            'role' => 'sometimes|in:owner,driver,all',
        ]);

        $target = $this->resolveTargetUser($request, forbidForeignUserId: true);
        $role = $this->resolveAnalyticsRole($request, $target);
        $period = $request->input('period');
        $anchor = CarbonImmutable::createFromFormat('Y-m-d', $request->input('date'))->startOfDay();
        $format = $request->input('format');

        $periodData = $this->analytics->periodCounters($target->id, $period, $anchor, $role);
        $trips = $this->analytics->tripRowsForPeriod($target->id, $period, $anchor, $role);
        [$from, $to] = $this->analytics->periodBounds($period, $anchor);

        $payload = [
            'user' => $target,
            'period' => $period,
            'date' => $anchor->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'counters' => $periodData['counters'],
            'trips' => $trips,
        ];

        $basename = 'reporte-viajes-'.$anchor->format('Y-m-d');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.user-period', $payload);

            return $pdf->download($basename.'.pdf');
        }

        return $this->streamXlsx($payload, $basename.'.xlsx');
    }

    /**
     * Admin may pass user_id. Non-admins:
     * - user-summary: ignore foreign user_id (legacy behaviour).
     * - analytics/export: 403 when asking for another user.
     */
    private function resolveTargetUser(Request $request, bool $forbidForeignUserId): User
    {
        $caller = $request->user();

        if ($caller->isAdmin() && $request->filled('user_id')) {
            return User::findOrFail($request->integer('user_id'));
        }

        if (
            $forbidForeignUserId
            && $request->filled('user_id')
            && $request->integer('user_id') !== $caller->id
        ) {
            abort(403, 'Forbidden');
        }

        return $caller;
    }

    /**
     * role=owner|driver|all. Si no se pide, usa el modo efectivo del usuario
     * objetivo. Sin capacidades todavía → "all" (owner_id OR driver_id) para
     * no ocultar viajes recién asignados como conductor.
     */
    private function resolveAnalyticsRole(Request $request, User $target): string
    {
        if ($request->filled('role')) {
            return $request->input('role');
        }

        if (! $target->hasOwnerCapability() && ! $target->hasDriverCapability()) {
            return 'all';
        }

        return $target->effectiveWorkMode();
    }

    private function streamXlsx(array $payload, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');

        $user = $payload['user'];
        $counters = $payload['counters'];
        $userName = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->email ?? '');

        $sheet->setCellValue('A1', 'Reporte de viajes');
        $sheet->setCellValue('A2', 'Usuario');
        $sheet->setCellValue('B2', $userName);
        $sheet->setCellValue('A3', 'Email');
        $sheet->setCellValue('B3', $user->email);
        $sheet->setCellValue('A4', 'Periodo');
        $sheet->setCellValue('B4', $payload['period']);
        $sheet->setCellValue('A5', 'Desde');
        $sheet->setCellValue('B5', $payload['from']);
        $sheet->setCellValue('A6', 'Hasta');
        $sheet->setCellValue('B6', $payload['to']);

        $sheet->setCellValue('A8', 'Contadores');
        $sheet->setCellValue('A9', 'Viajes totales');
        $sheet->setCellValue('B9', $counters['trips_total']);
        $sheet->setCellValue('A10', 'Completados');
        $sheet->setCellValue('B10', $counters['trips_completed']);
        $sheet->setCellValue('A11', 'Cancelados');
        $sheet->setCellValue('B11', $counters['trips_cancelled']);
        $sheet->setCellValue('A12', 'Pendientes');
        $sheet->setCellValue('B12', $counters['trips_pending']);
        $sheet->setCellValue('A13', 'Ingresos');
        $sheet->setCellValue('B13', $counters['revenue']);
        $sheet->setCellValue('A14', 'Ticket medio');
        $sheet->setCellValue('B14', $counters['avg_ticket']);
        $sheet->setCellValue('A15', '% completados');
        $sheet->setCellValue('B15', $counters['completion_rate']);

        $headers = ['Fecha', 'Hora', 'Cliente', 'Origen', 'Destino', 'Estado', 'Precio'];
        $headerRow = 17;
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, $headerRow], $header);
        }

        $row = $headerRow + 1;
        foreach ($payload['trips'] as $trip) {
            $sheet->setCellValue("A{$row}", $trip->trip_date?->format('Y-m-d'));
            $sheet->setCellValue("B{$row}", $trip->trip_time);
            $sheet->setCellValue("C{$row}", $trip->client_name);
            $sheet->setCellValue("D{$row}", $trip->origin);
            $sheet->setCellValue("E{$row}", $trip->destination);
            $sheet->setCellValue("F{$row}", $trip->status);
            $sheet->setCellValue(
                "G{$row}",
                $trip->status === 'completed' ? (float) $trip->final_price : null
            );
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total ingresos');
        $sheet->setCellValue("G{$row}", $counters['revenue']);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
