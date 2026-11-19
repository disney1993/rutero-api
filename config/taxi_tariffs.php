<?php

/*
| Tarifas interurbanas máximas de taxi por comunidad autónoma (referencia).
|
| En España las tarifas URBANAS las fija cada ayuntamiento y las INTERURBANAS
| cada comunidad autónoma, con carácter de máximas (LOTT y ROTT). Rutero usa
| las interurbanas como referencia porque la mayoría de sus servicios son
| traslados entre municipios. Los VTC tienen precios libres.
|
| km_basis indica cómo se aplica el precio por km:
|  - closed_circuit: se cobran los km de ida y los de vuelta al punto de
|    partida (circuito cerrado), así que por km de trayecto cuesta el doble;
|  - one_way: solo se cobran los km del servicio;
|  - unknown: la norma consultada no lo especifica.
| route_km_price es el equivalente por km de trayecto, que es lo que Rutero
| multiplica por la distancia de la ruta.
|
| Revisar cada año (suelen actualizarse en diciembre-marzo). Última revisión:
| 6 de octubre de 2026.
*/

return [
    'reviewed_at' => '2026-10-06',

    // Precio/km por defecto de Rutero. Algo por encima de la mediana porque
    // el precio sugerido de Rutero es solo distancia × precio/km: no suma
    // bajada de bandera, mínimo de percepción, esperas ni recargos nocturnos
    // y festivos, que las tarifas oficiales sí cobran aparte.
    'default_route_km_price' => 1.50,

    // Mediana de route_km_price de las comunidades con datos verificados
    // (horario diurno laborable), como referencia.
    'median_route_km_price' => 1.40,

    'regions' => [
        [
            'code' => 'AN', 'name' => 'Andalucía', 'year' => 2026,
            'km_price' => 0.71, 'km_price_special' => 0.82, 'km_basis' => 'unknown', 'route_km_price' => null,
            'min_fare' => 3.83,
            'source' => 'BOJA n.º 49, 12/03/2026 (tarifas interurbanas 2026, congeladas respecto a 2025)',
            'source_url' => 'https://juntadeandalucia.es/boja/2026/49/46',
        ],
        [
            'code' => 'AR', 'name' => 'Aragón', 'year' => 2026,
            'km_price' => 0.714, 'km_price_special' => 0.7875, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.43,
            'min_fare' => null,
            'source' => 'Orden FOM/1708/2025, de 26 de noviembre (BOA n.º 238, 10/12/2025)',
            'source_url' => 'https://noticias.juridicas.com/base_datos/CCAA/980438-orden-fom-1708-2025-de-26-nov-ca-aragon-se-regula-el-regimen-tarifario.html',
        ],
        [
            'code' => 'AS', 'name' => 'Principado de Asturias', 'year' => 2022,
            'km_price' => 0.65, 'km_price_special' => 0.78, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.30,
            'min_fare' => 3.99,
            'source' => 'BOPA, 24/10/2022 (última actualización localizada)',
            'source_url' => 'https://laadministracionaldia.inap.es/noticia.asp?id=1227072',
        ],
        [
            'code' => 'CL', 'name' => 'Castilla y León', 'year' => 2026,
            'km_price' => 0.70, 'km_price_special' => 0.80, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.40,
            'min_fare' => 4.05,
            'source' => 'Resolución de la D. G. de Transportes y Logística (BOCyL, diciembre de 2025), en vigor desde el 1/1/2026',
            'source_url' => 'https://www.heraldodeleon.es/articulo/leon/fijadas-nuevas-tarifas-maximas-taxi-interurbano-aplicar-enero-2026/20251205083050061373.html',
        ],
        [
            'code' => 'CT', 'name' => 'Cataluña', 'year' => 2026,
            'km_price' => 0.82, 'km_price_special' => 0.89, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.64,
            'min_fare' => null,
            'source' => 'Orden TER/215/2025, de 22 de diciembre (bajada de bandera 7,25 €)',
            'source_url' => 'https://noticias.juridicas.com/base_datos/CCAA/982434-orden-ter-215-2025-de-22-dic-ca-cataluna-tarifas-aplicables-a-los-servicios.html',
        ],
        [
            'code' => 'VC', 'name' => 'Comunitat Valenciana', 'year' => 2026,
            'km_price' => 1.46, 'km_price_special' => 1.68, 'km_basis' => 'one_way', 'route_km_price' => 1.46,
            'min_fare' => null,
            'source' => 'Tarifas interurbanas 2026 publicadas en el DOGV (bajada de bandera 2,25 €), según Todo Taxi',
            'source_url' => 'https://todotaxi.org/tarifas-del-taxi-interurbano-en-la-comunitat-valenciana-2026/',
        ],
        [
            'code' => 'GA', 'name' => 'Galicia', 'year' => 2022,
            'km_price' => 1.20, 'km_price_special' => 1.44, 'km_basis' => 'one_way', 'route_km_price' => 1.20,
            'min_fare' => 3.60,
            'source' => 'DOG, 06/05/2022 (vehículos sin taxímetro; última actualización localizada)',
            'source_url' => 'https://www.xunta.gal/dog/Publicados/2022/20220506/AnuncioG0533-270422-0003_es.html',
        ],
        [
            'code' => 'RI', 'name' => 'La Rioja', 'year' => 2023,
            'km_price' => 0.62, 'km_price_special' => 0.75, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.24,
            'min_fare' => 3.50,
            'source' => 'Orden STE/10/2023, de 13 de marzo (vigente según Unitaxi Rioja)',
            'source_url' => 'https://www.unitaxirioja.es/tarifas-interurbanas/',
        ],
        [
            'code' => 'MD', 'name' => 'Comunidad de Madrid', 'year' => 2026,
            'km_price' => 1.40, 'km_price_special' => 1.60, 'km_basis' => 'one_way', 'route_km_price' => 1.40,
            'min_fare' => null,
            'source' => 'Resolución de 1/12/2025 de la D. G. de Transportes y Movilidad (BOCM n.º 288, 03/12/2025); inicio de servicio 2,55 €',
            'source_url' => 'https://www.bocm.es/boletin/CM_Orden_BOCM/2025/12/03/BOCM-20251203-38.PDF',
        ],
        [
            'code' => 'MC', 'name' => 'Región de Murcia', 'year' => 2024,
            'km_price' => 0.60, 'km_price_special' => 0.71, 'km_basis' => 'closed_circuit', 'route_km_price' => 1.20,
            'min_fare' => 3.29,
            'source' => 'Orden de 19/06/2024 de la Consejería de Fomento e Infraestructuras (BORM n.º 148, 27/06/2024)',
            'source_url' => 'https://www.carm.es/web/descarga?ARCHIVO=Orden+establecen+tarifas+para+los+servicios+p%C3%BAblicos+de+transporte+interurbano+de+viajeros+por+carretera+en+veh%C3%ADculos+de+turismo+VT.pdf&ALIAS=ARCH&IDCONTENIDO=191567&IDTIPO=60&RASTRO=c398%24m71486',
        ],
    ],

    // Comunidades sin tarifa interurbana verificada en esta revisión: la app
    // las lista e indica que se consulte el boletín oficial correspondiente.
    'unverified' => [
        'Illes Balears', 'Canarias', 'Cantabria', 'Castilla-La Mancha', 'Extremadura',
        'Comunidad Foral de Navarra', 'País Vasco', 'Ceuta', 'Melilla',
    ],
];
