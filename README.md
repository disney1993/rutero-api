# Rutero API

API en Laravel para **Rutero**, una aplicación pensada para propietarios de vehículos y conductores que gestionan rutas de transporte de clientes (traslados, taxis privados, viajes concertados, etc.).

## ¿Qué hace Rutero?

- Un **propietario** (`owner`) registra sus vehículos y planifica rutas: cliente, origen, destino, fecha/hora, precio estimado (calculado a partir de la distancia y un precio por km configurable) y estado (pendiente, completada, rechazada, cancelada).
- El propietario genera un **código mensual** que comparte con las personas que conducen para él; al unirse con ese código, un **conductor** (`driver`) puede ver y crear rutas asociadas a ese propietario, actualizar su estado y el precio final, sin poder ver los informes de ingresos del propietario.
- Un **administrador** (`admin`) tiene visibilidad y gestión completa de usuarios y rutas de toda la plataforma (panel de administración, búsqueda de usuarios, edición de rol/plan, borrado de cuentas).
- La distancia entre origen y destino se calcula automáticamente por carretera (OSRM) a partir del autocompletado de direcciones (Nominatim), y sirve para sugerir el precio de cada ruta.

Este repositorio es el backend (API REST con Sanctum para autenticación). El cliente móvil/web está en [`rutero-react`](../rutero-react).

## Requisitos

- PHP 8.4+ (en este entorno: `C:\tools\php85\php.exe`, **no** el PHP 8.3 incluido con Laragon)
- Composer
- MySQL (Laragon lo incluye)
- Node.js + npm (solo para compilar assets con Vite)

## Instalación

```powershell
cd c:\laragon\www\rutero-api
composer install
npm install
copy .env.example .env
C:\tools\php85\php.exe artisan key:generate
```

Configura la base de datos en `.env` (por defecto `DB_DATABASE=backend_api`, usuario `root` sin contraseña en Laragon):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=backend_api
DB_USERNAME=root
DB_PASSWORD=
```

Ejecuta las migraciones y, opcionalmente, siembra datos de demostración (usuarios, vehículos y rutas de ejemplo):

```powershell
C:\tools\php85\php.exe artisan migrate --force
C:\tools\php85\php.exe artisan db:seed
```

El seeder crea un `admin`, un `owner` y un `driver` de ejemplo (`admin@example.test` / `owner@example.test` / `driver@example.test`, contraseña `secret123`), además de decenas de usuarios, vehículos y rutas aleatorias para poder probar listados, calendario e informes con volumen de datos realista.

## Ejecutar la API

```powershell
cd c:\laragon\www\rutero-api
C:\tools\php85\php.exe artisan serve --host=127.0.0.1 --port=8000
```

- API: http://127.0.0.1:8000
- Health check: http://127.0.0.1:8000/api/health

## Cierre automático de viajes (cron)

El comando `rutas:auto-complete` marca como **completadas y pagadas en efectivo** las rutas pendientes cuyo viaje ya ha terminado, para que el conductor no tenga que tocar el móvil al volante. Una ruta se cierra con lo primero que ocurra:

1. **Empieza el siguiente viaje** de la misma unidad de trabajo: el conductor asignado o, si no lo hay, el vehículo. Se evalúa por unidad, no sobre todo el sistema.
2. **Se alcanza el fin estimado**: `inicio + espera inicial (10 min) + d + 10 % de d (tráfico) + margen final (5 min, bajada y cobro)`, donde `d` es la duración por carretera de OSRM (`estimated_duration_min`). Todos los valores son configurables por cada propietario. Sin duración se estima con los km a 50 km/h y, sin km, con la duración por defecto del propietario. Las rutas sin hora se cierran al final de su día.

Solo afecta a rutas `pending` que el cron no haya procesado antes. No cambia la forma de pago ni el precio final si ya estaban indicados. Las rutas cerradas quedan con `auto_completed = true` y aparecen en "Por revisar" (`GET /api/rutas?needs_review=1`) hasta que alguien las revisa (`POST /api/rutas/{id}/review`) o las edita. Si se vuelven a poner en `pending`, se deshace el cierre y el cron no las vuelve a cerrar. Cada propietario puede desactivarlo o ajustar los márgenes (`PATCH /api/user/preferences`: `auto_complete_enabled`, `auto_complete_start_delay_min`, `auto_complete_margin_min`, `auto_complete_margin_pct`, `auto_complete_default_duration_min`). Las horas se interpretan en `RUTERO_TIMEZONE` (por defecto `Europe/Madrid`).

Está programado cada minuto en `routes/console.php`, así que hace falta que el planificador de Laravel esté en marcha:

```powershell
# En local (deja la terminal abierta):
C:\tools\php85\php.exe artisan schedule:work

# Ejecutarlo una vez a mano:
C:\tools\php85\php.exe artisan rutas:auto-complete
```

En producción, añade al crontab del servidor:

```
* * * * * cd /ruta/a/rutero-api && php artisan schedule:run >> /dev/null 2>&1
```

## Ejecutar el frontend (rutero-react)

Con la API anterior corriendo, en otra terminal:

```powershell
cd c:\laragon\www\rutero-react
npm install
npm run web
```

Más detalles (Expo Go, Android/iOS, configuración de Google Sign-In) en [`rutero-react/README.md`](../rutero-react/README.md).

## Ejecutar los tests

```powershell
cd c:\laragon\www\rutero-api
C:\tools\php85\php.exe artisan test
```

## Autenticación y roles

Registro/login por email o Google (`id_token`). Roles disponibles:

- `admin`: ve y gestiona todos los usuarios y rutas.
- `owner` (propietario): crea rutas, genera un `owner_code` mensual para compartir con conductores, gestiona sus vehículos y rutas.
- `driver` (conductor): al registrarse puede indicar un `owner_code` para asociarse a un propietario; puede crear rutas, marcarlas como completadas/canceladas/rechazadas y actualizar `final_price` y `payment_method`, pero no puede borrar rutas que no creó él mismo.

## Endpoints principales

- `POST /api/register` — Registro con `first_name`, `last_name`, `email`, `password`. Opcional `role` (`admin|owner|driver`) y `owner_code` si `role=driver`.
- `POST /api/login` — Login con `email` y `password`.
- `POST /api/auth/google/mobile` — Envía `id_token` de Google; el backend lo verifica y devuelve `access_token`.
- `GET /api/user` — Usuario autenticado (token Sanctum).
- `GET|POST|PUT|DELETE /api/rutas` — Gestión de rutas. Filtrado por rol: el admin ve todo, el owner ve las suyas, el driver ve las del propietario al que está asociado y las que él mismo creó.
- `GET|POST|PUT|DELETE /api/vehicles` — Gestión de vehículos del propietario. No se puede borrar un vehículo con rutas asociadas.
- `POST /api/owner/codes` / `POST /api/driver/join-code` — Generar y canjear el código mensual que asocia conductores a un propietario.
- `GET /api/admin/*` — Panel de administración (solo `admin`): búsqueda/edición/borrado de usuarios e informes agregados.

## Notas

- Si usas Laragon, asegúrate de que MySQL (y Apache, si usas virtual hosts) estén arrancados antes de iniciar la API.
- La tabla `rutas` se llamó originalmente `trips`; algunas columnas internas (`trip_date`, `trip_time`, etc.) conservan ese nombre histórico y no afectan a la API pública.
