<?php

namespace Database\Factories;

use App\Models\Jornada;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class JornadaFactory extends Factory
{
    protected $model = Jornada::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'date' => now()->toDateString(),
            'label' => 'A',
            'code' => strtoupper(Str::random(8)),
            'allow_create' => true,
            'allow_edit' => true,
            'allow_delete' => false,
        ];
    }
}
