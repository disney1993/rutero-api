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
