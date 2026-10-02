## Configuración inicial de Laravel

Una vez creado el archivo `.env` a partir de `.env.example`, continuar con los siguientes pasos.

### 1. Generar la APP_KEY de Laravel

```bash
docker compose exec app php artisan key:generate
```

Esto generará automáticamente el valor de:

```env
APP_KEY=
```

dentro del archivo `.env`.

---

### 2. Verificar la conexión a MariaDB

Revisar que la configuración de base de datos en `.env` coincida con la definida en `docker-compose.yml`.

Ejemplo:

```env
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=auth_api
DB_USERNAME=auth_user
DB_PASSWORD=secret
```

Aunque la base de datos sea MariaDB, Laravel utiliza:

```env
DB_CONNECTION=mysql
```

mediante el driver `pdo_mysql`.

---

## Laravel Passport

### 3. Verificar si Laravel Passport está instalado

```bash
docker compose exec app composer show laravel/passport
```

También se puede verificar mediante Artisan:

```bash
docker compose exec app php artisan list | grep passport
```

Si Passport está correctamente instalado, deberían aparecer comandos relacionados con Passport.

### 4. Instalar Laravel Passport si no está instalado

```bash
docker compose exec app composer require laravel/passport
```

---

### 5. Ejecutar las migraciones

```bash
docker compose exec app php artisan migrate
```

Esto creará las tablas necesarias de Laravel y Passport en MariaDB.

---

### 6. Instalar/configurar Passport

```bash
docker compose exec app php artisan passport:install
```

Este comando prepara Laravel Passport y puede generar los clientes OAuth necesarios.

Si únicamente se necesitan generar las claves OAuth:

```bash
docker compose exec app php artisan passport:keys
```

Las claves se generan normalmente en:

```text
storage/oauth-private.key
storage/oauth-public.key
```

Estas claves no deben versionarse en Git.

Verificar que `.gitignore` contenga:

```gitignore
/storage/oauth-private.key
/storage/oauth-public.key
```

---

## Verificaciones

### Ver las rutas disponibles

```bash
docker compose exec app php artisan route:list
```

### Ver comandos disponibles de Passport

```bash
docker compose exec app php artisan list | grep passport
```

### Ver estado de las migraciones

```bash
docker compose exec app php artisan migrate:status
```

---

## Comandos útiles

### Ingresar al container de PHP

```bash
docker compose exec app bash
```

### Ejecutar comandos Artisan

```bash
docker compose exec app php artisan
```

### Ejecutar Composer

```bash
docker compose exec app composer
```

### Limpiar caches de Laravel

```bash
docker compose exec app php artisan optimize:clear
```

### Ver los containers activos

```bash
docker compose ps
```

### Ver logs

```bash
docker compose logs -f
```

### Ver logs del container PHP

```bash
docker compose logs -f app
```

### Detener el entorno

```bash
docker compose down
```

### Volver a levantarlo

```bash
docker compose up -d
```

## Stack local

El entorno local utiliza:

- Laravel 13
- PHP 8.5-FPM
- Nginx
- MariaDB 11.8
- Laravel Passport
- Docker Compose

El entorno de producción utiliza LiteSpeed como servidor web, mientras que Nginx se utiliza únicamente en el entorno Docker local.