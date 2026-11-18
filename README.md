# Rutero API

Backend Laravel 12 (API REST + Sanctum) para **Rutero**: gestión de rutas/traslados para propietarios de taxis y conductores.

## Descripción

- **Owner**: vehículos propios, planificación de rutas (cliente, origen/destino, fecha/hora, precio por km, estado), códigos mensuales para asociar conductores.
- **Driver**: se une a un owner por código; trabaja por **jornadas** del día (hasta 3: A–C); puede crear/editar rutas de la jornada (sin borrar).
- **Admin**: usuarios, vehículos, rutas, auditorías; modo de cuenta `both` solo para admin.
- **Geocoding / distancia**: Google Maps (Places + Distance Matrix) con fallback Nominatim/OSRM.
- **Auth**: email/password o Google OAuth (`id_token` / Socialite).
- Soft delete + auditoría en entidades sensibles.

Cliente: [`rutero-react`](../rutero-react).

## Requisitos

- PHP **8.4+** (recomendado el de PATH: `C:\tools\php85\php.exe`; evitar el PHP 8.3 de Laragon si causa conflictos)
- Composer, MySQL (Laragon), Node/npm (Vite opcional)

## Instalación

```powershell
cd C:\laragon\www\rutero-api
composer install
copy .env.example .env
php artisan key:generate
```

En `.env` configura DB y, si aplica:

```env
DB_DATABASE=backend_api
DB_USERNAME=root
DB_PASSWORD=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_MAPS_API_KEY=
```

```powershell
php artisan migrate --force
php artisan db:seed
```

Usuarios demo (password `secret123`): `admin@example.test`, `owner@example.test`, `driver@example.test`.

## Comandos de uso

### Arrancar la API

```powershell
cd C:\laragon\www\rutero-api
php artisan serve --host=127.0.0.1 --port=8000
```

| Recurso        | URL                                      |
|----------------|------------------------------------------|
| API            | http://127.0.0.1:8000                    |
| Health         | http://127.0.0.1:8000/api/health         |

### Tests

```powershell
cd C:\laragon\www\rutero-api
php artisan test
```

### Utilidades

```powershell
php artisan migrate
php artisan db:seed
php artisan route:list --path=api
php artisan config:clear
```

### Frontend (otra terminal)

```powershell
cd C:\laragon\www\rutero-react
npm install
npm run web
```

Detalle: [`rutero-react/README.md`](../rutero-react/README.md).

## Roles y auth

| Rol      | Capacidad principal                                      |
|----------|----------------------------------------------------------|
| `admin`  | Todo + panel admin + `account_mode=both`                 |
| `owner`  | Vehículos, rutas, códigos, jornadas de sus conductores   |
| `driver` | Jornadas del día, crear/editar rutas (sin delete)        |

- `POST /api/register`, `POST /api/login`
- `POST /api/auth/google/mobile` — body: `id_token`
- `GET /api/user` — Sanctum bearer token

## Endpoints principales

| Área        | Rutas |
|-------------|--------|
| Rutas       | `GET\|POST\|PUT\|DELETE /api/rutas` |
| Vehículos   | `GET\|POST\|PUT\|DELETE /api/vehicles` |
| Jornadas    | `/api/jornadas` (y variantes owner/driver) |
| Códigos     | `POST /api/owner/codes`, `POST /api/driver/join-code` |
| Geocode     | `/api/geocode/*` |
| Admin       | `/api/admin/*` |
| Auditoría   | según rutas de audit |

## Notas

- Arranca MySQL en Laragon antes de servir la API.
- Columnas históricas `trip_date` / `trip_time` en rutas (nombre interno; la API habla de rutas).
