<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CarCatalogController extends Controller
{
    // Catálogo estático de referencia (marcas más comunes en España y sus
    // modelos más habituales). No pretende ser exhaustivo: si un usuario no
    // encuentra su marca/modelo, el formulario permite escribirlo a mano.
    private const CATALOG = [
        'SEAT' => ['Ibiza', 'León', 'Arona', 'Ateca', 'Tarraco', 'Alhambra'],
        'Renault' => ['Clio', 'Megane', 'Captur', 'Kadjar', 'Scenic', 'Twingo'],
        'Peugeot' => ['208', '308', '2008', '3008', '5008', 'Partner'],
        'Citroën' => ['C3', 'C4', 'C5 Aircross', 'Berlingo', 'C1'],
        'Volkswagen' => ['Golf', 'Polo', 'Passat', 'Tiguan', 'T-Roc', 'Touran'],
        'Toyota' => ['Corolla', 'Yaris', 'C-HR', 'RAV4', 'Prius', 'Aygo'],
        'Ford' => ['Fiesta', 'Focus', 'Kuga', 'Puma', 'Mondeo', 'Transit'],
        'Opel' => ['Corsa', 'Astra', 'Mokka', 'Crossland', 'Grandland'],
        'BMW' => ['Serie 1', 'Serie 3', 'Serie 5', 'X1', 'X3', 'X5'],
        'Mercedes-Benz' => ['Clase A', 'Clase C', 'Clase E', 'GLA', 'GLC', 'Vito'],
        'Audi' => ['A1', 'A3', 'A4', 'A6', 'Q3', 'Q5'],
        'Hyundai' => ['i20', 'i30', 'Tucson', 'Kona', 'Santa Fe'],
        'Kia' => ['Rio', 'Ceed', 'Sportage', 'Niro', 'Sorento'],
        'Nissan' => ['Micra', 'Qashqai', 'Juke', 'X-Trail', 'Leaf'],
        'Fiat' => ['500', 'Panda', 'Tipo', '500X', 'Doblo'],
        'Dacia' => ['Sandero', 'Duster', 'Logan', 'Jogger', 'Spring'],
        'Skoda' => ['Fabia', 'Octavia', 'Kamiq', 'Karoq', 'Kodiaq'],
        'Mazda' => ['Mazda2', 'Mazda3', 'CX-3', 'CX-5', 'CX-30'],
        'Honda' => ['Civic', 'Jazz', 'CR-V', 'HR-V'],
        'Volvo' => ['V40', 'V60', 'XC40', 'XC60', 'XC90'],
        'Mini' => ['Cooper', 'Countryman', 'Clubman'],
        'Land Rover' => ['Defender', 'Discovery', 'Range Rover Evoque', 'Range Rover Sport'],
        'Jeep' => ['Renegade', 'Compass', 'Wrangler', 'Avenger'],
        'Suzuki' => ['Swift', 'Vitara', 'S-Cross', 'Ignis'],
        'Cupra' => ['Leon', 'Formentor', 'Born', 'Ateca'],
        'Tesla' => ['Model 3', 'Model Y', 'Model S', 'Model X'],
        'Alfa Romeo' => ['Giulietta', 'Giulia', 'Stelvio', 'Tonale'],
        'Chevrolet' => ['Spark', 'Aveo', 'Captiva'],
        'Mitsubishi' => ['Space Star', 'ASX', 'Outlander', 'Eclipse Cross'],
        'DS' => ['DS 3', 'DS 4', 'DS 7'],
    ];

    public function makes()
    {
        return response()->json(array_keys(self::CATALOG));
    }

    public function models(Request $request)
    {
        $request->validate(['make' => 'required|string|max:100']);

        $models = collect(self::CATALOG)->first(
            fn ($_, $make) => strtolower($make) === strtolower($request->make)
        );

        return response()->json($models ?? []);
    }
}
