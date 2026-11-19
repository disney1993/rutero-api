<?php

namespace App\Services;

use App\Models\Ruta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Analytics and export rows for trips where the user is owner or driver.
 * Cancelled trips are soft-deleted; they count in counters but never in revenue.
 */
class UserAnalyticsService
{
    /**
     * Base query scoped by role.
     * - owner: trips where the user is the fleet owner
     * - driver: trips worked as driver (joined day/month codes)
     * - all: owner_id OR driver_id (legacy / admin overview)
     */
    public function baseQuery(int $userId, string $role = 'all'): Builder
    {
        $role = in_array($role, ['owner', 'driver', 'all'], true) ? $role : 'all';

        return Ruta::query()->where(function ($q) use ($userId, $role) {
            if ($role === 'owner') {
                $q->where('owner_id', $userId);
            } elseif ($role === 'driver') {
                $q->where('driver_id', $userId);
            } else {
                $q->where('owner_id', $userId)->orWhere('driver_id', $userId);
            }
        });
    }

    /**
     * Lifetime summary fields used by GET /reports/user-summary.
     *
     * @return array{rutas_total: int, rutas_by_status: \Illuminate\Support\Collection, revenue_completed: float}
     */
    public function lifetimeSummary(int $userId, string $role = 'all'): array
    {
        $query = $this->baseQuery($userId, $role);

        return [
            'rutas_total' => (clone $query)->withTrashed()->count(),
            'rutas_by_status' => (clone $query)->withTrashed()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'revenue_completed' => $this->money((clone $query)->where('status', 'completed')->sum('final_price')),
        ];
    }

    /**
     * Counters for a period plus comparison against the previous period.
     *
     * @return array{counters: array, previous_counters: array}
     */
    public function periodCounters(int $userId, string $period, CarbonImmutable $anchorDate, string $role = 'all'): array
    {
        [$from, $to] = $this->periodBounds($period, $anchorDate);
        [$prevFrom, $prevTo] = $this->previousPeriodBounds($period, $anchorDate);

        $counters = $this->countersForRange($userId, $from, $to, $role);
        $previous = $this->countersForRange($userId, $prevFrom, $prevTo, $role);

        $counters['delta_revenue'] = $this->money($counters['revenue'] - $previous['revenue']);
        $counters['delta_trips'] = $counters['trips_total'] - $previous['trips_total'];

        return [
            'counters' => $counters,
            'previous_counters' => $previous,
        ];
    }

    /**
     * Daily completed revenue for every day in the given month.
     *
     * @return list<array{label: string, date: string, revenue: float}>
     */
    public function dailyRevenueSeries(int $userId, int $year, int $month, string $role = 'all'): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $daysInMonth = $start->daysInMonth;

        $totals = $this->revenueByDateKey(
            $userId,
            $start,
            $start->endOfMonth(),
            '%Y-%m-%d',
            $role
        );

        $series = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $start->setDay($day)->format('Y-m-d');
            $series[] = [
                'label' => sprintf('%02d', $day),
                'date' => $date,
                'revenue' => $this->money($totals[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Monthly completed revenue for all 12 months of the year.
     *
     * @return list<array{label: string, year: int, month: int, revenue: float}>
     */
    public function monthlyRevenueSeries(int $userId, int $year, string $role = 'all'): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $totals = $this->revenueByDateKey(
            $userId,
            $start,
            $start->endOfYear(),
            '%Y-%m',
            $role
        );

        $series = [];
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $series[] = [
                'label' => sprintf('%02d', $month),
                'year' => $year,
                'month' => $month,
                'revenue' => $this->money($totals[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Trip rows for the export list (includes cancelled via withTrashed).
     *
     * @return Collection<int, Ruta>
     */
    public function tripRowsForPeriod(int $userId, string $period, CarbonImmutable $anchorDate, string $role = 'all'): Collection
    {
        [$from, $to] = $this->periodBounds($period, $anchorDate);

        return $this->baseQuery($userId, $role)
            ->withTrashed()
            ->whereDate('trip_date', '>=', $from->toDateString())
            ->whereDate('trip_date', '<=', $to->toDateString())
            ->orderBy('trip_date')
            ->orderBy('trip_time')
            ->get([
                'id',
                'client_name',
                'origin',
                'destination',
                'trip_date',
                'trip_time',
                'status',
                'final_price',
            ]);
    }

    /**
     * Full analytics payload for the controller.
     *
     * @return array<string, mixed>
     */
    public function analyticsFor(User $target, string $period, CarbonImmutable $anchorDate, string $role = 'all'): array
    {
        $role = in_array($role, ['owner', 'driver', 'all'], true) ? $role : 'all';
        $periodData = $this->periodCounters($target->id, $period, $anchorDate, $role);

        return [
            'user' => $target->only(['id', 'first_name', 'last_name', 'email', 'avatar_color']),
            'period' => $period,
            'date' => $anchorDate->toDateString(),
            'role' => $role,
            'counters' => $periodData['counters'],
            'previous_counters' => $periodData['previous_counters'],
            'charts' => [
                'daily' => $this->dailyRevenueSeries($target->id, $anchorDate->year, $anchorDate->month, $role),
                'monthly' => $this->monthlyRevenueSeries($target->id, $anchorDate->year, $role),
            ],
            'vehicles_total' => $role === 'driver' ? 0 : $target->vehicles()->count(),
            'has_owner_capability' => $target->hasOwnerCapability(),
            'has_driver_capability' => $target->hasDriverCapability(),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodBounds(string $period, CarbonImmutable $anchorDate): array
    {
        return match ($period) {
            'day' => [$anchorDate->startOfDay(), $anchorDate->endOfDay()],
            'month' => [$anchorDate->startOfMonth(), $anchorDate->endOfMonth()],
            'year' => [$anchorDate->startOfYear(), $anchorDate->endOfYear()],
            default => throw new \InvalidArgumentException("Unsupported period: {$period}"),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousPeriodBounds(string $period, CarbonImmutable $anchorDate): array
    {
        return match ($period) {
            'day' => $this->periodBounds('day', $anchorDate->subDay()),
            'month' => $this->periodBounds('month', $anchorDate->subMonthNoOverflow()),
            'year' => $this->periodBounds('year', $anchorDate->subYear()),
            default => throw new \InvalidArgumentException("Unsupported period: {$period}"),
        };
    }

    /**
     * @return array{
     *     trips_total: int,
     *     trips_completed: int,
     *     trips_cancelled: int,
     *     trips_pending: int,
     *     revenue: float,
     *     avg_ticket: float,
     *     completion_rate: float
     * }
     */
    private function countersForRange(int $userId, CarbonImmutable $from, CarbonImmutable $to, string $role = 'all'): array
    {
        $scoped = $this->baseQuery($userId, $role)
            ->withTrashed()
            ->whereDate('trip_date', '>=', $from->toDateString())
            ->whereDate('trip_date', '<=', $to->toDateString());

        $tripsTotal = (clone $scoped)->count();
        $tripsCompleted = (clone $scoped)->where('status', 'completed')->count();
        $tripsCancelled = (clone $scoped)->where('status', 'cancelled')->count();
        $tripsPending = (clone $scoped)->where('status', 'pending')->count();

        $revenue = $this->money(
            (clone $scoped)->where('status', 'completed')->sum('final_price')
        );

        $avgTicket = $tripsCompleted > 0
            ? $this->money($revenue / $tripsCompleted)
            : 0.0;

        $completionRate = $tripsTotal > 0
            ? $this->money(($tripsCompleted / $tripsTotal) * 100)
            : 0.0;

        return [
            'trips_total' => $tripsTotal,
            'trips_completed' => $tripsCompleted,
            'trips_cancelled' => $tripsCancelled,
            'trips_pending' => $tripsPending,
            'revenue' => $revenue,
            'avg_ticket' => $avgTicket,
            'completion_rate' => $completionRate,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function revenueByDateKey(
        int $userId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $strftimeFormat,
        string $role = 'all'
    ): array {
        // SQLite uses strftime; MySQL uses DATE_FORMAT. Format is always from an allowlist.
        $expression = $this->bucketExpression($strftimeFormat);

        $rows = $this->baseQuery($userId, $role)
            ->where('status', 'completed')
            ->whereDate('trip_date', '>=', $from->toDateString())
            ->whereDate('trip_date', '<=', $to->toDateString())
            ->selectRaw("{$expression} as bucket, COALESCE(SUM(final_price), 0) as revenue")
            ->groupByRaw($expression)
            ->pluck('revenue', 'bucket');

        $mapped = [];
        foreach ($rows as $key => $value) {
            $mapped[(string) $key] = $this->money($value);
        }

        return $mapped;
    }

    private function bucketExpression(string $strftimeFormat): string
    {
        $driver = Ruta::query()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return match ($strftimeFormat) {
                '%Y-%m-%d' => "strftime('%Y-%m-%d', trip_date)",
                '%Y-%m' => "strftime('%Y-%m', trip_date)",
                default => throw new \InvalidArgumentException('Unsupported date format'),
            };
        }

        return match ($strftimeFormat) {
            '%Y-%m-%d' => "DATE_FORMAT(trip_date, '%Y-%m-%d')",
            '%Y-%m' => "DATE_FORMAT(trip_date, '%Y-%m')",
            default => throw new \InvalidArgumentException('Unsupported date format'),
        };
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
