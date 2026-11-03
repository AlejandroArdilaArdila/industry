<?php

namespace App\Services\ServiceGlobal;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Funciones compartidas para llamar stored procedures de MySQL, usadas por
 * los distintos Services (Facturacion, Cotizacion, etc.) via inyeccion de
 * dependencias. Cubre dos convenciones de SP:
 * - Los que exponen su resultado via parametros OUT + variables de sesion
 *   (@campo), leidos con un SELECT posterior sobre la misma conexion.
 * - Los que ya devuelven su resultado directamente en el propio CALL
 *   (via un SELECT interno), sin OUT ni variables de sesion.
 */
class ServiceGlobalService
{
    public function ejecutarProcedimiento(string $procedimiento, array $parametros, array $camposSalida): array
    {
        $marcadores = implode(', ', array_fill(0, count($parametros), '?'));
        $variables = implode(', ', array_map(fn ($campo) => "@{$campo}", $camposSalida));

        DB::connection('mysql')->statement(
            "CALL {$procedimiento}({$marcadores}, {$variables})",
            $parametros
        );

        $seleccion = implode(', ', array_map(fn ($campo) => "@{$campo} AS {$campo}", $camposSalida));

        return (array) DB::connection('mysql')->selectOne("SELECT {$seleccion}");
    }

    public function ejecutarProcedimientoConResultado(string $procedimiento, array $parametros): array
    {
        $marcadores = implode(', ', array_fill(0, count($parametros), '?'));

        $filas = DB::connection('mysql')->select("CALL {$procedimiento}({$marcadores})", $parametros);

        return (array) ($filas[0] ?? []);
    }

    /**
     * Igual que ejecutarProcedimientoConResultado(), pero para SP que internamente
     * hacen CALL a otro SP que TAMBIEN termina en un SELECT propio (ej.
     * sp_pos_validar_factura_pos -> sp_pos_crear_ticket_v1): MySQL devuelve un
     * result set por cada SELECT ejecutado en la cadena de CALLs anidados, y
     * DB::select()/PDOStatement::fetchAll() sin iterar solo ve el primero. Aqui se
     * recorren todos con PDOStatement::nextRowset() y se devuelve el ULTIMO (el
     * del SP que se esta llamando, no el de sus dependencias internas).
     */
    public function ejecutarProcedimientoUltimoResultado(string $procedimiento, array $parametros): array
    {
        $marcadores = implode(', ', array_fill(0, count($parametros), '?'));

        // A diferencia de DB::select()/DB::statement(), el PDOStatement::execute()
        // crudo (necesario aqui para poder iterar nextRowset()) no normaliza los
        // bindings: un bool false se castea a '' en vez de 0, lo que revienta
        // columnas TINYINT con "Incorrect integer value". Se replica a mano el
        // mismo prepareBindings() que usa el query builder de Laravel.
        $parametros = array_map(fn ($valor) => is_bool($valor) ? (int) $valor : $valor, $parametros);

        $statement = DB::connection('mysql')->getPdo()->prepare("CALL {$procedimiento}({$marcadores})");
        $statement->execute($parametros);

        $ultimaFila = [];

        do {
            $filas = $statement->fetchAll(\PDO::FETCH_ASSOC);
            if (! empty($filas)) {
                $ultimaFila = $filas[0];
            }
        } while ($statement->nextRowset());

        return $ultimaFila;
    }

    /**
     * Version de ejecutarProcedimientoConResultado() para SPs con un unico
     * parametro JSON (ver sp_pos_entrada_salida_crear). Hay que castear
     * explicitamente con CAST(? AS JSON): a diferencia de un CALL literal
     * tecleado a mano, MySQL no infiere el tipo JSON de un valor enviado
     * como string via prepared statement.
     */
    public function ejecutarProcedimientoJson(string $procedimiento, array $payload): array
    {
        $json = json_encode($payload);

        $filas = DB::connection('mysql')->select("CALL {$procedimiento}(CAST(? AS JSON))", [$json]);

        return (array) ($filas[0] ?? []);
    }

    /**
     * Arma el mismo CALL con los valores ya interpolados (listo para pegar
     * en un cliente MySQL), lo deja en procedimientos.log y lo manda al
     * portapapeles del sistema para depurar a mano. Si no hay utilidad de
     * portapapeles disponible en el entorno, igual queda en el log.
     *
     * $camposSalida es null para SPs que ya devuelven su resultado en el
     * propio CALL (sin OUT ni variables de sesion, ver
     * ejecutarProcedimientoConResultado).
     */
    public function copiarSqlAlPortapapeles(string $procedimiento, array $parametros, ?array $camposSalida = null): void
    {
        $sql = $this->armarSqlLiteral($procedimiento, $parametros, $camposSalida);

        $this->registrarEnLog($procedimiento, $sql);

        $this->copiarAlPortapapeles($sql);
    }

    /**
     * Version de copiarSqlAlPortapapeles() para SPs de un unico parametro JSON.
     */
    public function copiarSqlAlPortapapelesJson(string $procedimiento, array $payload): void
    {
        $sql = $this->armarSqlLiteralJson($procedimiento, json_encode($payload));

        $this->registrarEnLog($procedimiento, $sql);

        $this->copiarAlPortapapeles($sql);
    }

    /**
     * Deja una entrada de texto libre en procedimientos.log (sin portapapeles,
     * sin armar SQL) - para diagnosticos puntuales que no son un SP fallido,
     * ej. detectar que un cliente esta mandando campos nulos que no deberia.
     */
    public function registrarTextoEnLog(string $etiqueta, string $texto): void
    {
        $this->registrarEnLog($etiqueta, $texto);
    }

    private function copiarAlPortapapeles(string $sql): void
    {
        foreach ([['xclip', '-selection', 'clipboard'], ['wl-copy'], ['xsel', '--clipboard', '--input'], ['pbcopy']] as $comando) {
            try {
                $proceso = new Process($comando);
                $proceso->setInput($sql);
                $proceso->run();

                if ($proceso->isSuccessful()) {
                    return;
                }
            } catch (\Throwable) {
                // El binario no esta disponible en este entorno; se prueba el siguiente.
            }
        }
    }

    /**
     * Arma el CALL con los valores ya interpolados (y el SELECT de las
     * variables de sesion, si aplica), listo para pegar en un cliente MySQL.
     */
    private function armarSqlLiteral(string $procedimiento, array $parametros, ?array $camposSalida = null): string
    {
        $valores = implode(', ', array_map([$this, 'aLiteralSql'], $parametros));

        if ($camposSalida === null) {
            return "CALL {$procedimiento}({$valores});";
        }

        $variables = implode(', ', array_map(fn ($campo) => "@{$campo}", $camposSalida));
        $seleccion = implode(', ', array_map(fn ($campo) => "@{$campo} AS {$campo}", $camposSalida));

        return "CALL {$procedimiento}({$valores}, {$variables});\nSELECT {$seleccion};";
    }

    private function armarSqlLiteralJson(string $procedimiento, string $json): string
    {
        return "CALL {$procedimiento}(CAST({$this->aLiteralSql($json)} AS JSON));";
    }

    private function registrarEnLog(string $procedimiento, string $sql): void
    {
        file_put_contents(
            base_path('procedimientos.log'),
            '['.now()->toDateTimeString()."] {$procedimiento}\n{$sql}\n\n",
            FILE_APPEND
        );
    }

    private function aLiteralSql(mixed $valor): string
    {
        if ($valor === null) {
            return 'NULL';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        return DB::connection('mysql')->getPdo()->quote((string) $valor);
    }
}
