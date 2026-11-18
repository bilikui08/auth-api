<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La aplicación pasa de guardar fechas con hora en UTC a guardarlas en hora argentina
 * (APP_TIMEZONE=America/Argentina/Buenos_Aires, UTC-3 todo el año). Los registros que ya existían
 * están en UTC, así que se les restan 3 horas para que sean consistentes con los nuevos.
 *
 * Solo toca columnas timestamp/datetime (las de tipo date no tienen hora). Las descubre del esquema,
 * así no depende de una lista escrita a mano. Al correr vía `migrate` queda registrada y no se repite.
 */
return new class extends Migration
{
    private const SKIP_TABLES = ['migrations'];

    public function up(): void
    {
        $this->shift(-3);
    }

    public function down(): void
    {
        $this->shift(3);
    }

    private function shift(int $hours): void
    {
        DB::transaction(function () use ($hours) {
            foreach ($this->timeColumns() as [$table, $column]) {
                $this->shiftColumn($table, $column, $hours);
            }
        });
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function timeColumns(): array
    {
        $columns = [];
        $database = DB::getDatabaseName();
        $isMysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, self::SKIP_TABLES, true)) {
                continue;
            }

            // En MySQL/MariaDB getTables() lista las tablas de TODAS las bases a las que el usuario tiene
            // acceso: sin este filtro, una tabla con el mismo nombre en otra base se procesaría dos veces
            // y las fechas se correrían 6 horas en lugar de 3.
            if ($isMysql && isset($table['schema']) && $table['schema'] !== $database) {
                continue;
            }

            foreach (Schema::getColumns($name) as $column) {
                if (in_array(strtolower($column['type_name']), ['timestamp', 'datetime'], true)) {
                    // Clave única por tabla.columna: nunca se desplaza dos veces la misma columna
                    $columns["{$name}.{$column['name']}"] = [$name, $column['name']];
                }
            }
        }

        return array_values($columns);
    }

    private function shiftColumn(string $table, string $column, int $hours): void
    {
        $t = DB::getQueryGrammar()->wrapTable($table);
        $c = DB::getQueryGrammar()->wrap($column);

        $expression = DB::getDriverName() === 'sqlite'
            ? "datetime({$c}, '{$hours} hours')"
            : "DATE_ADD({$c}, INTERVAL {$hours} HOUR)";

        DB::update("UPDATE {$t} SET {$c} = {$expression} WHERE {$c} IS NOT NULL");
    }
};
