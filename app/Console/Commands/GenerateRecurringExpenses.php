<?php

namespace App\Console\Commands;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Crea las copias mensuales de los gastos periódicos. Para cada plantilla
 * (recurring_monthly = true) y cada mes desde el siguiente al de la plantilla
 * hasta el actual, crea el gasto del mismo día (o el último del mes si ese
 * día no existe) cuando ya ha llegado esa fecha. No duplica: si ya existe la
 * copia de ese mes, aunque se haya borrado, no se vuelve a crear.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring';

    protected $description = 'Crea los gastos periódicos (mensuales) que tocan hasta hoy';

    public function handle(): int
    {
        $created = self::generate();
        $this->info("Gastos periódicos creados: {$created}");

        return self::SUCCESS;
    }

    public static function generate(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(config('rutero.auto_complete.timezone'))->startOfDay();
        $created = 0;

        Expense::where('recurring_monthly', true)->chunkById(200, function ($templates) use ($today, &$created) {
            foreach ($templates as $template) {
                $start = CarbonImmutable::parse($template->date)->startOfMonth()->addMonth();
                for ($month = $start; $month->lessThanOrEqualTo($today->startOfMonth()); $month = $month->addMonth()) {
                    $day = min((int) $template->date->format('d'), $month->daysInMonth);
                    $date = $month->setDay($day);
                    if ($date->greaterThan($today)) {
                        continue;
                    }
                    $exists = Expense::withTrashed()
                        ->where('recurring_parent_id', $template->id)
                        ->whereYear('date', $month->year)
                        ->whereMonth('date', $month->month)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    Expense::create([
                        'owner_id' => $template->owner_id,
                        'date' => $date->toDateString(),
                        'category' => $template->category,
                        'description' => $template->description,
                        'amount' => $template->amount,
                        'vat_amount' => $template->vat_amount,
                        'deductible' => $template->deductible,
                        'recurring_monthly' => false,
                        'recurring_parent_id' => $template->id,
                        'vehicle_id' => $template->vehicle_id,
                        'driver_id' => $template->driver_id,
                        'payment_method' => $template->payment_method,
                        'notes' => $template->notes,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }
}
