# Auth API

Microservicio de autenticación desarrollado con Laravel 13 y Laravel Passport.

## Stack

- Laravel 13
- PHP 8.5-FPM
- Laravel Passport
- MariaDB 11.8
- Nginx
- Docker Compose

El entorno Docker utiliza Nginx exclusivamente para desarrollo local.

El entorno de producción utiliza LiteSpeed como servidor web.

---

# Arquitectura local

```text
Cliente
  │
  ▼
Nginx
  │
  ▼
PHP 8.5-FPM
  │
  ▼
Laravel 13
  │
  ├── Laravel Passport
  │
  └── Eloquent
        │
        ▼
   MariaDB 11.8
```

---

# Requisitos

Para levantar el proyecto localmente se necesita:

- Docker
- Docker Compose v2
- Git

No es necesario tener PHP, Composer, Nginx ni MariaDB instalados localmente si todo se ejecuta mediante Docker.

---

# Clonar el proyecto

```bash
git clone <URL_DEL_REPOSITORIO>
cd auth-api
```

---

# Configurar variables de entorno

Crear el archivo `.env` a partir de `.env.example`:

```bash
cp .env.example .env
```

La configuración de base de datos debe coincidir con los valores definidos en `docker-compose.yml`.

Ejemplo:

```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=auth_api
DB_USERNAME=auth_user
DB_PASSWORD=secret
```

Aunque se utilice MariaDB, Laravel utiliza:

```env
DB_CONNECTION=mysql
```

porque la conexión se realiza mediante el driver `pdo_mysql`.

---

# Construir las imágenes Docker

```bash
docker compose build
```

---

# Levantar los containers

```bash
docker compose up -d
```

Verificar que todos los servicios estén funcionando:

```bash
docker compose ps
```

Los servicios principales son:

```text
app
nginx
mariadb
```

---

# Instalar dependencias

Si el directorio `vendor/` no existe:

```bash
docker compose exec app composer install
```

Si `vendor/` ya existe y las dependencias están correctamente instaladas, este paso puede omitirse.

---

# Generar APP_KEY de Laravel

```bash
docker compose exec app php artisan key:generate
```

Esto completa automáticamente:

```env
APP_KEY=
```

dentro del archivo `.env`.

---

# Permisos de Laravel

Laravel necesita permisos de escritura sobre:

```text
storage/
bootstrap/cache/
```

Configurar el propietario:

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
```

Configurar permisos:

```bash
docker compose exec app chmod -R 775 storage bootstrap/cache
```

No utilizar:

```bash
chmod -R 777 .
```

---

# Laravel Passport

## Roles y permisos (N:N)

Un usuario puede tener **varios roles** y un rol puede estar asignado a **varios usuarios**
(tablas `roles` y `role_user`). Los roles `admin` y `user` son del sistema: se crean con la
migración, no se pueden renombrar ni borrar. Todo usuario nuevo nace con el rol `user`; el
registro **nunca** permite asignar otros.

`GET /api/auth/me` devuelve los roles como lista de nombres (`"roles": ["admin", "user"]`).
El API Gateway usa ese dato para sus reglas de acceso por rol.

### Endpoints (requieren Bearer y rol `admin`)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/auth/roles` | Lista los roles (con `users_count`) |
| POST | `/api/auth/roles` | Crea un rol: `{"name": "editor", "description": "..."}` |
| GET | `/api/auth/roles/{id}` | Detalle |
| PUT | `/api/auth/roles/{id}` | Actualiza nombre y/o descripción |
| DELETE | `/api/auth/roles/{id}` | Elimina (se desasigna de sus usuarios) |
| GET | `/api/auth/users/{id}/roles` | Roles de un usuario |
| PUT | `/api/auth/users/{id}/roles` | Reemplaza **todos** sus roles: `{"roles": ["user", "editor"]}` |
| POST | `/api/auth/users/{id}/roles/{roleId}` | Asigna un rol (idempotente) |
| DELETE | `/api/auth/users/{id}/roles/{roleId}` | Retira un rol |

Cada rol informa `members_count` / `organizations_count` (personas y empresas distintas que lo usan en las empresas) y `permissions`.
Protecciones (pantalla «Roles» de la administración del sistema): **un rol en uso no se borra** (esas personas quedarían fuera de su empresa por el borrado
en cascada: se rechaza con cuántas personas y empresas lo usan); **a un rol en uso no se le quitan todos los permisos**; **`admin` y `user` (roles
globales) no reciben permisos de empresa**; los permisos de `company_admin` no se tocan. Los permisos los define el código (catálogo): desde la
aplicación solo se asignan a roles.

Reglas: `name` en minúsculas, números, `-` y `_` (máx. 50) y único; los roles del sistema no
se renombran ni se eliminan; no se puede dejar al sistema sin ningún `admin`.

### Crear el primer administrador

Los endpoints exigen un admin, así que el primero se crea por consola:

```bash
docker compose exec app php artisan auth:role tu@mail.com admin            # asignar
docker compose exec app php artisan auth:role tu@mail.com admin --revoke   # retirar
```

(`php artisan db:seed` deja al usuario de desarrollo como admin.)

### Tokens de vida corta

Los microservicios validan el JWT solo con la clave pública, sin consultar esta BD. Por eso
el access token dura **15 minutos** (`PASSPORT_ACCESS_TOKEN_TTL_MINUTES`) y el refresh token
30 días (`PASSPORT_REFRESH_TOKEN_TTL_DAYS`). Un `logout` o un cambio de rol se nota en los
servicios cuando el token caduca o se renueva.

## Claims del access token

Los access tokens son JWT firmados (RS256) y los servicios (billing, etc.) los validan solo con la clave pública.
Además de los claims estándar (`aud`, `jti`, `iat`, `nbf`, `exp`, `sub`, `scopes`), llevan:

| Claim  | Valor                                  | Para qué                                                        |
|--------|----------------------------------------|-----------------------------------------------------------------|
| `name` | nombre del usuario al emitir el token  | que los servicios auditen "quién hizo qué" sin consultar a auth-api |
| `org`  | id de la empresa con la que se emitió  | de quién son los datos (billing filtra por esto)                |
| `permissions` | permisos de la persona en esa empresa | qué puede hacer (`sales.invoice.annul`, ...): los servicios chequean permisos, nunca roles |

- Los arma `App\Auth\Passport\ClaimsAccessToken`, registrada con `Passport::useAccessTokenEntity()` en `AppServiceProvider`.
  Es una copia de `Laravel\Passport\Bridge\AccessToken` porque league/oauth2-server construye el JWT en un método privado.
  **Revisarla al actualizar Passport o league/oauth2-server** (el test `AccessTokenClaimsTest` decodifica un token real).
- Se calculan al emitir el token (login y refresh): un cambio de nombre tarda hasta el siguiente refresh en reflejarse.
- **El JWT no está cifrado**: cualquiera que lo tenga puede leer los claims. No agregar datos sensibles (email, documentos).
- Los tokens emitidos antes de este cambio no traen `name`: los servicios deben tolerar su ausencia.
- `org` y `permissions` se calculan al emitir y al renovar (refresh): un cambio de rol o de pertenencia tarda hasta 15 min en verse.

## Empresas (organizaciones) y permisos por empresa

Una **empresa** reúne a varias personas, cada una con uno o más **roles** allí (`organization_user`): la misma persona puede ser
administradora en una empresa y lectora en otra. Un rol es un grupo de **permisos** (`permission_role`); los permisos se
llaman `modulo.recurso.accion` y su catálogo está en `App\Application\Organizations\PermissionCatalog`
(`php artisan permissions:sync` agrega los que falten; no quita nada). Roles de fábrica: `company_admin` (todos), `seller`,
`accountant`, `viewer`. Los usuarios que ya existían recibieron una empresa propia con **su mismo id** (`organizations.id = users.id`).

**Login en dos pasos, solo cuando hace falta:**

1. `POST /api/auth/login` valida usuario y contraseña.
   - Una empresa → entra directo: `data = {user, organization, tokens}`.
   - Varias → **no emite tokens**: `data = {user, requires_organization: true, selection_token, selection_expires_in, organizations: [{id, name}]}`.
   - Ninguna → 403 "Tu usuario no tiene empresas asignadas".
2. `POST /api/auth/select-organization {selection_token, organization_id}` → `{user, organization, tokens}`.
   El `selection_token` vale 5 minutos y **un solo uso**; no es un access token.

- `POST /api/auth/switch-organization {refresh_token, organization_id}`: cambia de empresa sin contraseña (emite tokens nuevos).
- `POST /api/auth/refresh` conserva la empresa de la sesión y la **revalida**: si la persona ya no pertenece (o la empresa se dio de baja) responde 401.
- `GET /api/auth/organizations` (Bearer): empresas de la persona y cuál es la actual (`current`). `GET /api/auth/me` suma `organization_id` y `permissions`.
- Todos los tokens salen por el grant propio `organization_selection` (login) o por el refresh que conserva la empresa
  (`App\Auth\Passport\*`). El grant `password` de `/oauth/token` ya no está habilitado.
- **El cliente OAuth debe tener el grant `organization_selection`** en `oauth_clients.grant_types` (la migración lo agrega a los
  clientes `password` existentes; un cliente nuevo hay que crearlo con ese grant).
- Comando de respaldo: `php artisan org:assign tu@mail.com <empresaId> seller [--revoke]`.

### Administración de empresas (API)

**Administrador de la empresa** (permiso `org.members.manage` en la empresa con la que se emitió el token; se consulta en la base, así que
un cambio de rol vale al instante):

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/auth/organization` | La empresa activa |
| GET | `/api/auth/organization/members` | Personas de la empresa con sus roles |
| POST | `/api/auth/organization/members` | Agrega a un usuario **existente**: `{"email": "...", "roles": ["seller"]}` |
| PUT | `/api/auth/organization/members/{userId}` | Reemplaza sus roles: `{"roles": ["viewer"]}` (mínimo uno) |
| DELETE | `/api/auth/organization/members/{userId}` | Lo quita (sus sesiones en esa empresa dejan de renovarse) |
| GET | `/api/auth/organization/roles` | Roles asignables con sus permisos |
| POST | `/api/auth/organization/invitations` | Suma a alguien por email: `{"email", "roles"}` → `status: "added"` (ya tenía cuenta: entra al instante y se le avisa) o `"invited"` (se le manda el enlace); `email_sent` dice si el correo salió |
| GET | `/api/auth/organization/invitations` | Invitaciones pendientes |
| DELETE | `/api/auth/organization/invitations/{id}` | Cancela una invitación pendiente |

Reglas: solo se asignan roles de empresa (los que tienen permisos; nunca los globales `admin` / `user`); la empresa siempre conserva
al menos un `company_admin`; no se puede tocar a personas de otra empresa.

**Administrador del sistema** (rol global `admin`): `GET|POST /api/auth/admin/organizations` (alta con su primer administrador:
`{"name", "tax_id"?, "admin_email"}`), `PUT /api/auth/admin/organizations/{id}` (`name`, `tax_id`, `is_active`: una empresa inactiva no deja
entrar ni renovar sesión), `GET /api/auth/admin/users?search=`, `GET /api/auth/permissions` (catálogo) y los roles con permisos
(`POST|PUT /api/auth/roles` aceptan `permissions: [...]`; los permisos de `company_admin` no se pueden cambiar).

## Verificar si Passport está instalado

```bash
docker compose exec app composer show laravel/passport
```

También se pueden consultar los comandos disponibles:

```bash
docker compose exec app php artisan list | grep passport
```

Si Passport no está instalado:

```bash
docker compose exec app composer require laravel/passport
```

---

# Ejecutar migraciones

```bash
docker compose exec app php artisan migrate
```

Para comprobar el estado:

```bash
docker compose exec app php artisan migrate:status
```

---

# Ejecutar Seeders

Ejecutar todos los seeders definidos en `DatabaseSeeder`:

```bash
docker compose exec app php artisan db:seed
```

También es posible ejecutar migraciones y seeders juntos:

```bash
docker compose exec app php artisan migrate --seed
```

Para ejecutar un seeder específico:

```bash
docker compose exec app php artisan db:seed --class=NombreDelSeeder
```

Ejemplo:

```bash
docker compose exec app php artisan db:seed --class=UserSeeder
```

---

# Generar las claves OAuth de Passport

Laravel Passport utiliza un par de claves RSA:

```text
storage/oauth-private.key
storage/oauth-public.key
```

Generarlas ejecutando:

```bash
docker compose exec --user www-data app php artisan passport:keys
```

Si las claves ya existen y se necesita regenerarlas:

```bash
docker compose exec --user www-data app php artisan passport:keys --force
```

---

# Permisos de las claves de Passport

Asignar las claves al usuario utilizado por PHP-FPM:

```bash
docker compose exec app chown www-data:www-data storage/oauth-private.key storage/oauth-public.key
```

La clave privada debe tener permisos restrictivos:

```bash
docker compose exec app chmod 600 storage/oauth-private.key
```

La clave pública puede ser legible:

```bash
docker compose exec app chmod 644 storage/oauth-public.key
```

Se puede verificar con:

```bash
docker compose exec app ls -la storage/oauth-*
```

El resultado debería ser similar a:

```text
-rw------- www-data www-data oauth-private.key
-rw-r--r-- www-data www-data oauth-public.key
```

---

# No versionar las claves OAuth

Verificar que `.gitignore` contenga:

```gitignore
/storage/oauth-private.key
/storage/oauth-public.key
```

La clave privada nunca debe subirse al repositorio.

---

# Habilitar Password Grant

Si el proyecto utiliza OAuth2 Password Grant, verificar que esté habilitado.

En:

```text
app/Providers/AppServiceProvider.php
```

debe existir:

```php
use Laravel\Passport\Passport;

public function boot(): void
{
    Passport::enablePasswordGrant();
}
```

---

# Crear el cliente OAuth Password Grant

Para generar:

```env
PASSPORT_PASSWORD_CLIENT_ID=
PASSPORT_PASSWORD_CLIENT_SECRET=
```

ejecutar:

```bash
docker compose exec app php artisan passport:client --password
```

Passport solicitará un nombre para el cliente.

Ejemplo:

```text
Auth API Password Client
```

Al finalizar mostrará información similar a:

```text
Client ID: 1
Client Secret: xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Copiar esos valores al archivo `.env`:

```env
PASSPORT_PASSWORD_CLIENT_ID=1
PASSPORT_PASSWORD_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

No generar manualmente estos valores.

Los valores deben provenir del comando:

```bash
php artisan passport:client --password
```

---

# Configuración de Passport

Si el proyecto utiliza `config/services.php` para acceder a las credenciales, verificar que exista:

```php
'passport' => [
    'password_client_id' => env('PASSPORT_PASSWORD_CLIENT_ID'),
    'password_client_secret' => env('PASSPORT_PASSWORD_CLIENT_SECRET'),
],
```

Luego de modificar `.env` ejecutar:

```bash
docker compose exec app php artisan optimize:clear
```

---

# Verificar el cliente OAuth

Se puede verificar directamente desde Laravel Tinker:

```bash
docker compose exec app php artisan tinker
```

Dentro de Tinker:

```php
DB::table('oauth_clients')->get();
```

Debe aparecer el cliente creado mediante:

```bash
php artisan passport:client --password
```

Para salir:

```php
exit
```

---

# Crear un usuario de prueba

Passport no crea usuarios.

Los usuarios pertenecen al modelo `User` de Laravel.

Si los seeders ya crean usuarios, este paso no es necesario.

Para crear uno manualmente:

```bash
docker compose exec app php artisan tinker
```

Dentro de Tinker:

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

User::create([
    'name' => 'Usuario Test',
    'email' => 'test@example.com',
    'password' => Hash::make('12345678'),
]);
```

Consultar usuarios:

```php
User::all();
```

Salir:

```php
exit
```

---

# Limpiar caché de Laravel

Después de modificar `.env`, configuración o Passport:

```bash
docker compose exec app php artisan optimize:clear
```

---

# Verificar las rutas

```bash
docker compose exec app php artisan route:list
```

---

# Verificar los comandos de Passport

```bash
docker compose exec app php artisan list | grep passport
```

---

# Flujo de instalación completo

Para una instalación nueva, el flujo recomendado es:

```bash
cp .env.example .env
```

Construir Docker:

```bash
docker compose build
```

Levantar containers:

```bash
docker compose up -d
```

Instalar dependencias si fuera necesario:

```bash
docker compose exec app composer install
```

Generar APP_KEY:

```bash
docker compose exec app php artisan key:generate
```

Configurar permisos:

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

Ejecutar migraciones y seeders:

```bash
docker compose exec app php artisan migrate --seed
```

Generar claves OAuth:

```bash
docker compose exec --user www-data app php artisan passport:keys
```

Configurar permisos de las claves:

```bash
docker compose exec app chown www-data:www-data storage/oauth-private.key storage/oauth-public.key

docker compose exec app chmod 600 storage/oauth-private.key
docker compose exec app chmod 644 storage/oauth-public.key
```

Crear cliente OAuth:

```bash
docker compose exec app php artisan passport:client --password
```

Copiar el resultado al `.env`:

```env
PASSPORT_PASSWORD_CLIENT_ID=
PASSPORT_PASSWORD_CLIENT_SECRET=
```

Finalmente limpiar caché:

```bash
docker compose exec app php artisan optimize:clear
```

Verificar rutas:

```bash
docker compose exec app php artisan route:list
```

---

# Reset completo de la base de datos

Durante desarrollo puede ser necesario eliminar completamente la base y volver a crearla.

Ejecutar:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Este comando:

```text
elimina todas las tablas
        ↓
ejecuta todas las migraciones
        ↓
ejecuta DatabaseSeeder
```

ADVERTENCIA:

Este comando elimina todos los datos existentes.

No utilizar en producción.

---

# Comandos Docker útiles

## Levantar containers

```bash
docker compose up -d
```

## Detener containers

```bash
docker compose down
```

## Reconstruir las imágenes

```bash
docker compose up -d --build
```

## Ver estado

```bash
docker compose ps
```

## Ver logs generales

```bash
docker compose logs -f
```

## Logs del container PHP

```bash
docker compose logs -f app
```

## Logs de Nginx

```bash
docker compose logs -f nginx
```

## Logs de MariaDB

```bash
docker compose logs -f mariadb
```

---

# Acceder al container PHP

```bash
docker compose exec app bash
```

Una vez dentro se pueden ejecutar normalmente comandos Laravel:

```bash
php artisan
```

```bash
php artisan route:list
```

```bash
php artisan migrate:status
```

Para salir:

```bash
exit
```

---

# Composer

Consultar versión:

```bash
docker compose exec app composer --version
```

Instalar dependencias:

```bash
docker compose exec app composer install
```

Actualizar dependencias:

```bash
docker compose exec app composer update
```

Agregar una dependencia:

```bash
docker compose exec app composer require vendor/package
```

---

# Artisan

Lista de comandos:

```bash
docker compose exec app php artisan list
```

Información del entorno Laravel:

```bash
docker compose exec app php artisan about
```

Rutas:

```bash
docker compose exec app php artisan route:list
```

Migraciones:

```bash
docker compose exec app php artisan migrate
```

Seeders:

```bash
docker compose exec app php artisan db:seed
```

Limpiar caches:

```bash
docker compose exec app php artisan optimize:clear
```

---

# Troubleshooting

## Laravel no puede escribir en `storage/logs`

Error típico:

```text
storage/logs/laravel.log:
Failed to open stream: Permission denied
```

Solución:

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

---

## Passport no puede leer la clave privada

Error típico:

```text
Key path "file:///var/www/html/storage/oauth-private.key"
does not exist or is not readable
```

Verificar que exista:

```bash
docker compose exec app ls -la storage/oauth-*
```

Si no existe:

```bash
docker compose exec --user www-data app php artisan passport:keys
```

Luego:

```bash
docker compose exec app chmod 600 storage/oauth-private.key
docker compose exec app chmod 644 storage/oauth-public.key
```

---

## El cliente OAuth no está configurado

Error:

```text
El cliente OAuth de Passport no está configurado.
```

Crear el cliente:

```bash
docker compose exec app php artisan passport:client --password
```

Copiar los valores generados a:

```env
PASSPORT_PASSWORD_CLIENT_ID=
PASSPORT_PASSWORD_CLIENT_SECRET=
```

Luego:

```bash
docker compose exec app php artisan optimize:clear
```

---

## Verificar conexión a MariaDB

Entrar al container:

```bash
docker compose exec app php artisan tinker
```

Dentro:

```php
DB::connection()->getPdo();
```

Si no devuelve una excepción, Laravel pudo conectarse correctamente.

También:

```php
DB::select('SELECT VERSION() AS version');
```

permite consultar la versión de MariaDB utilizada.

---

# Producción

El entorno Docker local utiliza:

```text
Nginx
+
PHP 8.5-FPM
+
Laravel 13
+
MariaDB 11.8
```

El hosting de producción utiliza:

```text
LiteSpeed
+
PHP 8.5
+
Laravel 13
+
MariaDB 11.8.x
```

Laravel no debe depender de configuraciones específicas de Nginx.

El Document Root del dominio/subdominio en producción debe apuntar al directorio:

```text
/public
```

y nunca al directorio raíz completo del proyecto.

---

# Seguridad

No versionar:

```text
.env
storage/oauth-private.key
storage/oauth-public.key
```

No utilizar permisos globales:

```bash
chmod -R 777 .
```

La clave privada de Passport debe mantenerse protegida:

```text
oauth-private.key → 600
oauth-public.key  → 644
```

Las credenciales:

```env
PASSPORT_PASSWORD_CLIENT_SECRET=
DB_PASSWORD=
```

no deben subirse al repositorio.

---

# Resumen de primera instalación

```bash
cp .env.example .env

docker compose build
docker compose up -d

docker compose exec app composer install

docker compose exec app php artisan key:generate

docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache

docker compose exec app php artisan migrate --seed

docker compose exec --user www-data app php artisan passport:keys

docker compose exec app chown www-data:www-data storage/oauth-private.key storage/oauth-public.key
docker compose exec app chmod 600 storage/oauth-private.key
docker compose exec app chmod 644 storage/oauth-public.key

docker compose exec app php artisan passport:client --password
```

Agregar al `.env` las credenciales generadas:

```env
PASSPORT_PASSWORD_CLIENT_ID=
PASSPORT_PASSWORD_CLIENT_SECRET=
```

Finalmente:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan route:list
```

Con esto el entorno debería quedar preparado para utilizar la API de autenticación con Laravel Passport.

## Invitaciones por email (no hay registro abierto)

`POST /api/auth/register` **no existe**: la única forma de tener una cuenta nueva es aceptar una invitación.

1. Quien administra una empresa (o la administración del sistema, al crear una empresa) invita por email con `POST /api/auth/organization/invitations`.
   Si el email ya tiene cuenta se lo suma al instante; si no, se crea una invitación y se manda un correo con el enlace
   `FRONTEND_URL/accept-invitation?token=...`.
2. La pantalla pública consulta `GET /api/auth/invitations/{token}` (a quién, a qué empresa, con qué roles) y acepta con
   `POST /api/auth/invitations/{token}/accept` `{name, password, password_confirmation}`: se crea la cuenta (email verificado), se la suma
   a la empresa con los roles invitados y se abre la sesión. Si en el medio el email ya tuvo cuenta, solo se lo suma (`status: "existing_user"`).

Seguridad: el token (64 caracteres al azar) **solo viaja por email**, nunca vuelve por la API (quien invita no lo ve: si no, podría aceptar
en nombre de otra persona); se guarda **hasheado** (SHA-256); vale **7 días**, **un solo uso**; reinvitar al mismo email invalida el enlace
anterior; un token desconocido, vencido, cancelado o usado responde lo mismo (404 con un único mensaje); los endpoints públicos tienen
límite de frecuencia (30/min consulta, 10/min aceptación). El correo sale en el momento (el hosting no tiene procesos en segundo plano): si
falla, la invitación queda guardada y se puede reenviar.

## Restablecer la contraseña (olvidé mi contraseña)

1. `POST /api/auth/forgot-password` `{email}`: si el email tiene cuenta, se le manda un correo (plantilla editable `password_reset`) con el enlace
   `FRONTEND_URL/reset-password?token=...&email=...`. **La respuesta es siempre la misma**, exista o no la cuenta, y si el correo no sale
   (servidor SMTP caído) se reporta pero tampoco se le cuenta a quien lo pidió.
2. La pantalla pública puede verificar el enlace sin gastarlo con `POST /api/auth/reset-password/check` `{email, token}` (200 o 422).
3. `POST /api/auth/reset-password` `{email, token, password, password_confirmation}` (mínimo 8 caracteres) cambia la contraseña, **consume el enlace**
   y **cierra todas las sesiones** de la persona (revoca sus access y refresh tokens).

Seguridad: usa el mecanismo de Laravel (`password_reset_tokens`): el token se guarda **hasheado**, **vale 60 minutos** (`auth.passwords.users.expire`),
es de **un solo uso** y pedir otro invalida el anterior; pedir de nuevo antes de 60 segundos no manda otro correo (respuesta idéntica). Un
token desconocido, vencido, usado o de otro email responde lo mismo (422 con un único mensaje). Límite de frecuencia: 5/min el pedido,
20/min la verificación y 10/min el cambio. No inicia sesión sola: después de cambiarla se ingresa con la nueva.

## Seguridad del ingreso: captcha y verificación en dos pasos

**Orden del ingreso:** usuario y contraseña (con captcha) → segundo factor → (si tiene varias empresas) elegir empresa → tokens. Sin el segundo
factor **nunca se emite un token**. Las rutas públicas de Passport (`/oauth/token`, etc.) están desactivadas: los tokens los emite solo la aplicación.

### reCAPTCHA v2 (login y "olvidé mi contraseña")
- El frontend manda la respuesta del widget en el campo `captcha`; el servidor la verifica contra Google (`RECAPTCHA_SECRET` en el `.env`;
  vacío = apagado, para desarrollo y tests). **Falla cerrado**: si Google no responde, se rechaza.
- `RECAPTCHA_HOSTNAMES` (coma) limita desde qué sitios vale: la misma clave puede estar en otros sitios y su captcha no debe servir acá
  (producción: `facturin.gustavoluis.me`; para probar en local, sumar `localhost`).
- Límites por minuto **independientes por ruta** (`RateLimiter::for` en `AppServiceProvider`): login 10, olvidé contraseña 5, verificación 2FA 15, etc.

### Verificación en dos pasos (TOTP)
Google Authenticator, Microsoft Authenticator, Authy, 1Password… (RFC 6238, implementación propia en `Totp`, verificada con los vectores del RFC).
**Está activa por defecto para todas las personas** (`users.two_factor_enabled` nace en `true`): quien no la configuró la configura en su
próximo ingreso (QR o clave manual) y recibe 10 códigos de recuperación de un solo uso. Se apaga desde la cuenta (pide contraseña y un código).

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/login` | Con 2FA devuelve `requires_two_factor`, `two_factor` (`verify`\|`setup`) y `challenge_token` (vale 10 min, un uso) |
| POST | `/api/auth/login/two-factor/setup` | `{challenge_token}` → `{secret, uri}` (primer ingreso) |
| POST | `/api/auth/login/two-factor/confirm` | `{challenge_token, code}` → activa, devuelve `recovery_codes` y sigue el ingreso |
| POST | `/api/auth/login/two-factor/verify` | `{challenge_token, code}` (código de la app o de recuperación) → sigue el ingreso |
| GET | `/api/auth/two-factor` | Estado: `enabled`, `confirmed`, `recovery_codes_remaining` |
| POST | `/api/auth/two-factor/enable` · `enable/confirm` | Volver a activarlo (secreto nuevo + código) |
| POST | `/api/auth/two-factor/disable` | `{password, code}` |
| POST | `/api/auth/two-factor/recovery-codes` | `{code}` → códigos nuevos (anulan los anteriores) |

Seguridad: el secreto y los códigos de recuperación se guardan **cifrados / hasheados** y nunca vuelven por la API (salvo al configurar);
un código **no sirve dos veces** (se guarda el último intervalo usado); el permiso temporal se anula tras 5 códigos erróneos y se bloquea la
verificación de esa persona tras 10 errores en 15 min; tolera 1 intervalo (30 s) de diferencia de reloj.

**Recuperar una cuenta** (perdió el celular y los códigos): `php artisan auth:2fa-reset email@dominio` (la obliga a configurarlo de nuevo en su
próximo ingreso) o con `--disable` (lo deja sin 2FA).

### Ajustes del sistema y plantillas de correo (administración del sistema)

El **servidor de correo (SMTP) y todo secreto viven solo en el `.env`**: nunca se editan desde la aplicación (quien pudiera cambiar el SMTP
controlaría los correos de invitación y de recuperación de cuentas, y la contraseña quedaría en cada backup de la base).

Lo que sí se edita desde la aplicación (rol global `admin`):

| Método | Ruta | Descripción |
|---|---|---|
| GET/PUT | `/api/auth/admin/system` | Nombre del sistema (`{"system_name": "Mi ERP"}`; vacío o `null` vuelve al `APP_NAME` del `.env`) |
| GET | `/api/auth/branding` | **Público**: solo el nombre del sistema (lo muestra el login antes de autenticar) |
| GET | `/api/auth/admin/email-templates` | Plantillas con el texto vigente, el original y sus variables |
| PUT | `/api/auth/admin/email-templates/{key}` | Guarda una versión propia: `{subject, heading, body, button_label}` |
| DELETE | `/api/auth/admin/email-templates/{key}` | Vuelve al original |
| POST | `/api/auth/admin/email-templates/{key}/preview` | Vista previa (correo completo en HTML con datos de ejemplo) de un borrador, sin guardarlo |
| POST | `/api/auth/admin/email-templates/{key}/test` | Manda el borrador **a la casilla de quien lo pide** (nunca a otra; 5 por minuto) |

Plantillas (`App\Application\Mail\EmailTemplateRegistry`): `invitation` y `added_to_organization`. Para sumar otra, agregarla ahí y usarla desde un
Mailable que extienda `TemplatedMail`. El texto admite las variables `{invitador}`, `{empresa}`, `{sistema}` (y `{dias}` en la invitación).

Seguridad de las plantillas (el texto lo escribe una persona y sale por tu remitente con un enlace de acceso):
- **Nunca se ejecuta como código** (no se compila como Blade): solo se reemplazan variables.
- **Todo se escapa**, el texto de la plantilla y los valores de las variables (los nombres de personas y empresas los escribe cualquier usuario):
  no pueden armar enlaces, imágenes ni HTML. Solo se admite `**negrita**`, párrafos y saltos de línea.
- **No admite enlaces ni HTML propios** (se rechaza al guardar): el enlace del botón lo agrega el sistema, así una plantilla editada no puede
  mandar a la gente a un sitio ajeno. Tampoco variables desconocidas, ni saltos de línea en el asunto (inyección de encabezados).
- **Siempre hay un original** en el código: si una versión propia falla al armarse, se usa el original y el correo sale igual.

**Configuración necesaria en producción** (`.env`): `MAIL_MAILER=smtp` con `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; `FRONTEND_URL` con la dirección donde se publique el frontend; y `APP_NAME=Facturín` (es el nombre
que aparece en el encabezado y el pie de los correos). Con `MAIL_MAILER=log` el correo se escribe en `storage/logs` en lugar de enviarse.
