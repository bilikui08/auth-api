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