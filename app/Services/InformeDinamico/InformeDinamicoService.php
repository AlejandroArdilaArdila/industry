<?php

namespace App\Services\InformeDinamico;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Puerto a Laravel de ConsultadinamicaApi (htdocs/custom/integrospos/class/api_consultadinamica.class.php),
 * que expone las consultas dinámicas guardadas por el módulo "informes" (tabla informes_cd_consulta)
 * marcadas con "para_app" a la App (IntegrosApp/BingoApp).
 *
 * Los guards anti-inyección (identificadorValido, esSelectSeguro, valorWhereSeguro, aliasSeguro,
 * validarExpresionManual, validarExpresionFiltro) están portados 1:1 desde
 * custom/informes/lib/consulta_dinamica_ajax.php: el SQL final se arma concatenando identificadores
 * de tabla/columna que vienen del payload guardado, así que cada pieza se valida contra
 * information_schema antes de entrar en el SQL.
 */
class InformeDinamicoService
{
    private const CONNECTION = 'mysql';

    public function listarConsultas(?string $app = null): array
    {
        $rows = DB::connection(self::CONNECTION)
            ->table('informes_cd_consulta')
            ->select(['rowid', 'nombre', 'descripcion', 'payload'])
            ->orderByDesc('tms')
            ->get();

        $consultas = [];
        foreach ($rows as $o) {
            $p = json_decode((string) $o->payload, true);
            if (! is_array($p)) {
                continue;
            }

            $paraApp = $p['para_app'] ?? '';
            if ($paraApp === '') {
                continue;
            }
            if ($app !== null && $app !== '' && $paraApp !== $app) {
                continue;
            }

            $consultas[] = $this->buildConsultaItem((int) $o->rowid, (string) $o->nombre, $o->descripcion, $paraApp, $p);
        }

        return ['total' => count($consultas), 'consultas' => $consultas];
    }

    public function obtenerConsulta(int $rowid): array
    {
        $o = DB::connection(self::CONNECTION)
            ->table('informes_cd_consulta')
            ->select(['rowid', 'nombre', 'descripcion', 'payload'])
            ->where('rowid', $rowid)
            ->first();

        if (! $o) {
            abort(response()->json(['error' => 'Consulta no encontrada'], 404));
        }

        $p = json_decode((string) $o->payload, true);
        if (! is_array($p)) {
            abort(response()->json(['error' => 'Payload inválido'], 400));
        }

        $paraApp = $p['para_app'] ?? '';
        if ($paraApp === '') {
            abort(response()->json(['error' => 'Esta consulta no está marcada para uso en App'], 403));
        }

        return $this->buildConsultaItem($rowid, (string) $o->nombre, $o->descripcion, $paraApp, $p);
    }

    public function ejecutarConsulta(int $rowid, Request $request): array
    {
        $o = DB::connection(self::CONNECTION)
            ->table('informes_cd_consulta')
            ->select(['rowid', 'nombre', 'payload'])
            ->where('rowid', $rowid)
            ->first();

        if (! $o) {
            abort(response()->json(['error' => 'Consulta no encontrada'], 404));
        }

        $p = json_decode((string) $o->payload, true);
        if (! is_array($p)) {
            abort(response()->json(['error' => 'Payload inválido'], 400));
        }

        if (($p['para_app'] ?? '') === '') {
            abort(response()->json(['error' => 'Esta consulta no está marcada para uso en App'], 403));
        }

        $entradasRaw = (array) $request->input('entradas', []);
        $entradas = [];
        foreach ($entradasRaw as $k => $v) {
            $entradas[ltrim((string) $k, ':')] = $v;
        }

        $limitInput = (int) $request->input('limit', 0);
        $offset = max(0, (int) $request->input('offset', 0));
        $orderBy = trim((string) $request->input('order_by', ''));
        $orderDir = strtoupper(trim((string) $request->input('order_dir', 'ASC')));
        $search = trim((string) $request->input('search', ''));

        if (! in_array($orderDir, ['ASC', 'DESC'], true)) {
            $orderDir = 'ASC';
        }

        $limit = $limitInput > 0 ? min($limitInput, 5000) : (int) ($p['limit'] ?? 200);
        if ($limit < 1) {
            $limit = 200;
        }

        $result = $this->construirSql($p, $entradas);
        if (isset($result['error'])) {
            abort(response()->json($result, 400));
        }
        $baseSql = $result['sql'];

        $this->relajarOnlyFullGroupBy();

        $metaPorAlias = [];
        foreach (($p['campos'] ?? []) as $c) {
            if (isset($c['activo']) && ! $c['activo']) {
                continue;
            }
            $key = ! empty($c['manual']) ? ($c['alias'] ?? '') : ($c['alias'] ?? ($c['campo'] ?? ''));
            if ($key === '') {
                continue;
            }
            $metaPorAlias[$key] = [
                'formato' => $c['formato'] ?? '',
                'ancho' => (int) ($c['ancho'] ?? 0),
                'fija' => $c['fija'] ?? '',
            ];
        }

        $colNames = $this->columnNames($baseSql);

        $searchClause = '';
        if ($search !== '' && ! empty($colNames)) {
            $likeVal = '%'.addcslashes($search, '%_\\').'%';
            $likeEsc = $this->escape($likeVal);
            $likeParts = array_map(
                fn ($col) => '`'.str_replace('`', '``', $col)."` LIKE '$likeEsc'",
                $colNames
            );
            $searchClause = ' WHERE ('.implode(' OR ', $likeParts).')';
        }

        $orderClause = '';
        if ($orderBy !== '' && in_array($orderBy, $colNames, true)) {
            $orderClause = ' ORDER BY `'.str_replace('`', '``', $orderBy).'` '.$orderDir;
        }

        $countSql = 'SELECT COUNT(*) AS _cd_total FROM ('.$baseSql.') AS _cd_count'.$searchClause;
        $countRow = DB::connection(self::CONNECTION)->selectOne($countSql);
        $total = (int) ($countRow->_cd_total ?? 0);

        $dataSql = 'SELECT * FROM ('.$baseSql.') AS _cd_base'.$searchClause.$orderClause
            .' LIMIT '.$limit.' OFFSET '.$offset;

        try {
            $filasObj = DB::connection(self::CONNECTION)->select($dataSql);
        } catch (\Throwable $e) {
            abort(response()->json(['error' => 'Error al ejecutar: '.$e->getMessage(), 'sql' => $baseSql], 500));
        }

        $columnas = [];
        foreach ($colNames as $nombre) {
            $meta = $metaPorAlias[$nombre] ?? ['formato' => '', 'ancho' => 0, 'fija' => ''];
            $columnas[] = ['nombre' => $nombre, 'formato' => $meta['formato'], 'ancho' => $meta['ancho'], 'fija' => $meta['fija']];
        }

        $filas = array_map(fn ($row) => (array) $row, $filasObj);

        return [
            'consulta' => ['rowid' => $rowid, 'nombre' => $o->nombre],
            'columnas' => $columnas,
            'total' => $total,
            'filas' => $filas,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function buildConsultaItem(int $rowid, string $nombre, ?string $descripcion, string $paraApp, array $p): array
    {
        $tipo = $p['tipoConsulta'] ?? 'dinamica';

        $whereAll = $p['where'] ?? [];
        $entradas = [];
        foreach ($whereAll as $i => $w) {
            if (empty($w['esEntrada'])) {
                continue;
            }
            $placeholder = $this->buildPlaceholder($w['entradaTitulo'] ?? '', (int) $i);
            $entrada = [
                'indice' => $i,
                'placeholder' => $placeholder,
                'titulo' => $w['entradaTitulo'] ?? '',
                'tipo' => $w['entradaTipo'] ?? 'texto',
                'campo' => ! empty($w['manual']) ? '' : ($w['tabla'] ?? '').'.'.($w['campo'] ?? ''),
                'operador' => $w['operador'] ?? '=',
                'valor_default' => $w['valor'] ?? '',
                'opciones' => null,
            ];

            if (($w['entradaTipo'] ?? '') === 'select' && ! empty($w['entradaOpciones'])) {
                $opts = $w['entradaOpciones'];
                if (($opts['tipo'] ?? '') === 'manual') {
                    $entrada['opciones'] = $opts['manual'] ?? [];
                } elseif (($opts['tipo'] ?? '') === 'consulta' && ! empty($opts['consulta'])) {
                    $entrada['opciones'] = $this->ejecutarOpcionesSql($opts['consulta']);
                    $entrada['opciones_consulta'] = $opts['consulta'];
                }
            }

            $entradas[] = $entrada;
        }

        $sqlPlantilla = null;
        $sqlError = null;
        $sqlResult = $this->construirSql($p, [], true);
        if (isset($sqlResult['error'])) {
            $sqlError = $sqlResult['error'];
        } else {
            $sqlPlantilla = $sqlResult['sql'];
        }

        $columnas = [];
        foreach (($p['campos'] ?? []) as $c) {
            if (isset($c['activo']) && ! $c['activo']) {
                continue;
            }
            $nombreCol = ! empty($c['manual']) ? ($c['alias'] ?? '') : ($c['alias'] ?? ($c['campo'] ?? ''));
            if ($nombreCol === '') {
                continue;
            }
            $columnas[] = [
                'nombre' => $nombreCol,
                'formato' => $c['formato'] ?? '',
                'ancho' => (int) ($c['ancho'] ?? 0),
                'fija' => $c['fija'] ?? '',
            ];
        }

        return [
            'rowid' => $rowid,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'para_app' => $paraApp,
            'tipo' => $tipo,
            'columnas' => $columnas,
            'entradas' => $entradas,
            'sql_plantilla' => $sqlPlantilla,
            'sql_error' => $sqlError,
        ];
    }

    /**
     * Genera un nombre de placeholder limpio a partir del título de la entrada.
     * Ej: "Fecha inicio" → ":fecha_inicio", "" → ":param_2"
     */
    private function buildPlaceholder(string $titulo, int $indice): string
    {
        if ($titulo === '') {
            return ':param_'.$indice;
        }
        $slug = strtolower(trim($titulo));
        $slug = preg_replace('/[áàäâ]/u', 'a', $slug);
        $slug = preg_replace('/[éèëê]/u', 'e', $slug);
        $slug = preg_replace('/[íìïî]/u', 'i', $slug);
        $slug = preg_replace('/[óòöô]/u', 'o', $slug);
        $slug = preg_replace('/[úùüû]/u', 'u', $slug);
        $slug = preg_replace('/[ñ]/u', 'n', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');

        return ':'.($slug ?: 'param_'.$indice);
    }

    private function ejecutarOpcionesSql(string $sql): array
    {
        if (! $this->esSelectSeguro($sql)) {
            return [];
        }

        $this->relajarOnlyFullGroupBy();

        $rows = DB::connection(self::CONNECTION)->select($sql.' LIMIT 500');

        $items = [];
        foreach ($rows as $row) {
            $vals = array_values((array) $row);
            $items[] = [
                'valor' => (string) ($vals[0] ?? ''),
                'etiqueta' => (string) ($vals[1] ?? $vals[0] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Construye el SQL final a partir del payload guardado por el constructor visual.
     *
     * @param  array  $entradas  Valores para los parámetros de entrada ['placeholder' => 'valor']
     * @param  bool  $conPlaceholders  Si true, pone :placeholder en vez del valor real (SQL plantilla)
     * @return array ['sql' => string] o ['error' => string]
     */
    private function construirSql(array $p, array $entradas = [], bool $conPlaceholders = false): array
    {
        $tipo = $p['tipoConsulta'] ?? 'dinamica';

        if ($tipo === 'sql') {
            $sql = trim($p['sqlAvanzado'] ?? '');
            if ($sql === '') {
                return ['error' => 'SQL vacío'];
            }

            // Las variables de posición $_WHEREn (payload.where[].sqlVar) solo se
            // resolvían en el JS del navegador (informe_dinamico.js), nunca en el
            // backend de Dolibarr: se sustituyen acá para poder ejecutar por API.
            // En modo plantilla (sql_plantilla de obtenerConsulta/listarConsultas)
            // se deja el SQL crudo tal cual, con los $_WHEREn visibles.
            if (! $conPlaceholders) {
                $whereResuelto = $this->resolverWhereEntradasParaSql($p['where'] ?? [], $entradas);
                $activos = array_values(array_filter($whereResuelto, fn ($w) => ($w['activo'] ?? true) !== false));

                $sub = $this->sustituirVariablesEnSql($sql, $activos);
                $condResto = $this->condicionesWhereASql($sub['resto'], $sub['sql']);
                $sql = $this->inyectarWhereEnSql($sub['sql'], $condResto);
            }

            if (! $this->esSelectSeguro($sql)) {
                return ['error' => 'El SQL no es un SELECT puro'];
            }

            return ['sql' => $sql];
        }

        if ($tipo === 'procedimiento') {
            $cuerpo = trim($p['procCuerpo'] ?? '');
            if ($cuerpo === '') {
                return ['error' => 'Cuerpo del procedimiento vacío'];
            }
            $interno = preg_replace('/^\s*BEGIN\b/i', '', $cuerpo);
            $interno = preg_replace('/\bEND\s*;?\s*$/i', '', $interno);
            $interno = rtrim(trim($interno), "; \t\n\r");
            if (! $this->esSelectSeguro($interno)) {
                return ['error' => 'El procedimiento solo puede contener SELECT'];
            }

            return ['sql' => $interno];
        }

        if (! empty($p['rawSql'])) {
            $sql = trim($p['rawSql']);
            if (! $this->esSelectSeguro($sql)) {
                return ['error' => 'rawSql no es un SELECT puro'];
            }

            return ['sql' => $sql];
        }

        // Dinámica — el payload usa "tablasVisibles", no "tablas"
        $tablas = $p['tablasVisibles'] ?? ($p['tablas'] ?? []);
        $campos = array_filter($p['campos'] ?? [], fn ($c) => ! isset($c['activo']) || $c['activo']);
        $joins = $p['joins'] ?? [];
        $where = $p['where'] ?? [];
        $groupBy = array_filter($p['groupBy'] ?? [], fn ($g) => ! isset($g['activo']) || $g['activo']);
        $orderBy = array_filter($p['orderBy'] ?? [], fn ($o) => ! isset($o['activo']) || $o['activo']);

        if (empty($campos) || empty($tablas)) {
            return ['error' => 'Sin campos o tablas'];
        }

        foreach ($tablas as $t) {
            if (! $this->identificadorValido($t)) {
                return ['error' => "Tabla inválida: $t"];
            }
        }

        // SELECT
        $selectParts = [];
        foreach ($campos as $c) {
            if (! empty($c['manual'])) {
                $exp = preg_replace('/--[^\n]*/', '', $c['expresion'] ?? '');
                $exp = trim(preg_replace('/\/\*[\s\S]*?\*\//', '', $exp));
                if ($exp === '' || ! $this->validarExpresionManual($exp)) {
                    continue;
                }
                $alias = $c['alias'] ?? '';
                $expr = "($exp)";
                if ($alias !== '' && $this->aliasSeguro($alias)) {
                    $expr .= " AS `$alias`";
                }
                $selectParts[] = $expr;

                continue;
            }
            $t = $c['tabla'] ?? '';
            $f = $c['campo'] ?? '';
            $fn = strtoupper($c['funcion'] ?? '');
            if (! $this->identificadorValido($t, $f)) {
                return ['error' => "Campo inválido: $t.$f"];
            }
            $expr = "`$t`.`$f`";
            if (in_array($fn, ['SUM', 'COUNT', 'AVG', 'MIN', 'MAX'], true)) {
                $expr = "$fn($expr)";
            }
            $alias = $c['alias'] ?? '';
            if ($alias !== '' && $this->aliasSeguro($alias)) {
                $expr .= " AS `$alias`";
            }
            $selectParts[] = $expr;
        }
        if (empty($selectParts)) {
            return ['error' => 'Sin columnas válidas'];
        }

        // FROM + JOINs
        $primera = $tablas[0];
        $fromSql = "`$primera`";
        $tablasEnFrom = [$primera];
        $pendientes = array_slice($tablas, 1);
        while (! empty($pendientes)) {
            $progreso = false;
            foreach ($pendientes as $idx => $t) {
                $joinInfo = null;
                foreach ($joins as $j) {
                    $ta = $j['tabla_a'] ?? '';
                    $fa = $j['campo_a'] ?? '';
                    $tb = $j['tabla_b'] ?? '';
                    $fb = $j['campo_b'] ?? '';
                    if (! $this->identificadorValido($ta, $fa) || ! $this->identificadorValido($tb, $fb)) {
                        continue;
                    }
                    if ($tb === $t && in_array($ta, $tablasEnFrom, true)) {
                        $joinInfo = ['src' => $ta, 'srcCol' => $fa, 'dstCol' => $fb];
                        break;
                    }
                    if ($ta === $t && in_array($tb, $tablasEnFrom, true)) {
                        $joinInfo = ['src' => $tb, 'srcCol' => $fb, 'dstCol' => $fa];
                        break;
                    }
                }
                if ($joinInfo) {
                    $fromSql .= " LEFT JOIN `$t` ON `{$joinInfo['src']}`.`{$joinInfo['srcCol']}` = `$t`.`{$joinInfo['dstCol']}`";
                    $tablasEnFrom[] = $t;
                    unset($pendientes[$idx]);
                    $progreso = true;
                }
            }
            $pendientes = array_values($pendientes);
            if (! $progreso) {
                return ['error' => "Sin JOIN para tabla: {$pendientes[0]}"];
            }
        }

        // WHERE
        $ops = ['=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'];
        $parts = [];
        foreach ($where as $idx => $w) {
            $conj = strtoupper($w['conj'] ?? 'AND');
            if (! in_array($conj, ['AND', 'OR'], true)) {
                $conj = 'AND';
            }
            $pre = $idx === array_key_first($where) ? '' : " $conj ";

            if (! empty($w['manual'])) {
                $exp = $w['expresion'] ?? '';
                if (! empty($w['esEntrada'])) {
                    $placeholder = $this->buildPlaceholder($w['entradaTitulo'] ?? '', (int) $idx);
                    if ($conPlaceholders) {
                        $parts[] = $pre.'('.$placeholder.')';

                        continue;
                    }
                    $key = ltrim($placeholder, ':');
                    $valReal = $entradas[$key] ?? null;
                    if ($valReal !== null) {
                        if (! $this->valorWhereSeguro($valReal)) {
                            return ['error' => "Valor inválido para $placeholder"];
                        }
                        if (strpos($exp, $placeholder) !== false) {
                            $exp = str_replace($placeholder, "'".$this->escape((string) $valReal)."'", $exp);
                        }
                    }
                }
                if (! $this->validarExpresionFiltro($exp)) {
                    return ['error' => "Expresión WHERE rechazada: $exp"];
                }
                $parts[] = $pre.'('.$exp.')';

                continue;
            }

            $t = $w['tabla'] ?? '';
            $f = $w['campo'] ?? '';
            $op = $w['operador'] ?? '=';
            $v = $w['valor'] ?? '';
            if (! $this->identificadorValido($t, $f)) {
                return ['error' => "Campo WHERE inválido: $t.$f"];
            }
            if (! in_array($op, $ops, true)) {
                return ['error' => "Operador inválido: $op"];
            }

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $parts[] = $pre."`$t`.`$f` $op";

                continue;
            }

            if (! empty($w['esEntrada'])) {
                $placeholder = $this->buildPlaceholder($w['entradaTitulo'] ?? '', (int) $idx);
                if ($conPlaceholders) {
                    $parts[] = $pre."`$t`.`$f` $op $placeholder";
                } else {
                    $key = ltrim($placeholder, ':');
                    $valReal = $entradas[$key] ?? $v;
                    if (! $this->valorWhereSeguro($valReal)) {
                        return ['error' => "Valor inválido para $placeholder"];
                    }
                    $parts[] = $pre."`$t`.`$f` $op '".$this->escape((string) $valReal)."'";
                }

                continue;
            }

            if (! $this->valorWhereSeguro($v)) {
                return ['error' => 'Valor WHERE rechazado por seguridad'];
            }
            $parts[] = $pre."`$t`.`$f` $op '".$this->escape((string) $v)."'";
        }
        $whereSql = $parts ? ' WHERE '.implode('', $parts) : '';

        // GROUP BY
        $groupSql = '';
        if (! empty($groupBy)) {
            $g = [];
            foreach ($groupBy as $gb) {
                if (! empty($gb['manual'])) {
                    $exp = $gb['expresion'] ?? '';
                    if (! $this->validarExpresionFiltro($exp)) {
                        return ['error' => "Expresión GROUP BY rechazada: $exp"];
                    }
                    $g[] = "($exp)";

                    continue;
                }
                $t = $gb['tabla'] ?? '';
                $f = $gb['campo'] ?? '';
                if (! $this->identificadorValido($t, $f)) {
                    return ['error' => "Campo GROUP inválido: $t.$f"];
                }
                $g[] = "`$t`.`$f`";
            }
            if ($g) {
                $groupSql = ' GROUP BY '.implode(', ', $g);
            }
        }

        // ORDER BY
        $orderSql = '';
        if (! empty($orderBy)) {
            $o = [];
            foreach ($orderBy as $ob) {
                $dir = strtoupper($ob['dir'] ?? 'ASC');
                if ($dir !== 'ASC' && $dir !== 'DESC') {
                    $dir = 'ASC';
                }
                if (! empty($ob['manual'])) {
                    $exp = $ob['expresion'] ?? '';
                    if (! $this->validarExpresionFiltro($exp)) {
                        return ['error' => "Expresión ORDER BY rechazada: $exp"];
                    }
                    $o[] = "($exp) $dir";

                    continue;
                }
                $t = $ob['tabla'] ?? '';
                $f = $ob['campo'] ?? '';
                if (! $this->identificadorValido($t, $f)) {
                    return ['error' => "Campo ORDER inválido: $t.$f"];
                }
                $o[] = "`$t`.`$f` $dir";
            }
            if ($o) {
                $orderSql = ' ORDER BY '.implode(', ', $o);
            }
        }

        $sql = 'SELECT '.implode(', ', $selectParts).' FROM '.$fromSql.$whereSql.$groupSql.$orderSql;

        if (! $this->esSelectSeguro($sql)) {
            return ['error' => 'SQL generado rechazado por seguridad'];
        }

        return ['sql' => $sql];
    }

    /**
     * Nombres de columna del SQL base (LIMIT 0, sin traer datos), vía metadata del statement PDO.
     */
    private function columnNames(string $baseSql): array
    {
        $pdo = DB::connection(self::CONNECTION)->getPdo();
        $stmt = $pdo->query('SELECT * FROM ('.$baseSql.') AS _cd_cols LIMIT 0');

        $names = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $names[] = $meta['name'] ?? '';
        }

        return array_values(array_filter($names, fn ($n) => $n !== ''));
    }

    private function identificadorValido(string $tabla, ?string $columna = null): bool
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $tabla)) {
            return false;
        }
        if ($columna !== null && ! preg_match('/^[A-Za-z0-9_]+$/', $columna)) {
            return false;
        }

        $dbName = (string) DB::connection(self::CONNECTION)->getDatabaseName();

        if ($columna === null) {
            $row = DB::connection(self::CONNECTION)->selectOne(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, $tabla]
            );
        } else {
            $row = DB::connection(self::CONNECTION)->selectOne(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $tabla, $columna]
            );
        }

        return $row !== null;
    }

    /**
     * Guard anti-escritura: garantiza que la consulta es un SELECT puro.
     */
    private function esSelectSeguro(string $sql): bool
    {
        $s = ltrim(html_entity_decode($sql, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! preg_match('/^SELECT\b/i', $s)) {
            return false;
        }

        $cleaned = rtrim($s, "; \t\n\r");
        if (preg_match('/;\s*\S/i', $cleaned)) {
            return false;
        }

        $prohibidas = [
            'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'REPLACE',
            'GRANT', 'REVOKE', 'RENAME', 'LOAD', 'CALL', 'EXEC', 'EXECUTE', 'HANDLER', 'LOCK',
            'UNLOCK', 'SHUTDOWN', 'FLUSH', 'KILL', 'PURGE', 'RESET', 'COMMIT', 'ROLLBACK',
            'SAVEPOINT', 'DO', 'OPTIMIZE', 'REPAIR', 'CHECKSUM', 'ANALYZE',
        ];
        foreach ($prohibidas as $kw) {
            if (preg_match('/\b'.preg_quote($kw, '/').'\b/i', $s)) {
                return false;
            }
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $s)) {
            return false;
        }
        if (preg_match('/\bSET\s+@/i', $s)) {
            return false;
        }

        return true;
    }

    /** Valida un valor de WHERE: sin backticks, sin secuencias peligrosas. */
    private function valorWhereSeguro($v): bool
    {
        if (! is_scalar($v)) {
            return false;
        }
        $v = (string) $v;
        if (strpos($v, '`') !== false) {
            return false;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|UNION\s+SELECT|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE|SLEEP\s*\(|BENCHMARK\s*\()/i', $v)) {
            return false;
        }

        return true;
    }

    /** Valida un alias de columna SQL (para AS `alias`). */
    private function aliasSeguro(?string $alias): bool
    {
        if ($alias === '' || $alias === null) {
            return false;
        }
        if (strlen($alias) > 200) {
            return false;
        }
        if (preg_match('/[`"\'\x00\r\n;\\\\]/', $alias)) {
            return false;
        }

        return true;
    }

    /** Valida una expresión SQL manual (campo calculado): sin escrituras, subqueries ni comentarios. */
    private function validarExpresionManual(string $exp): bool
    {
        $exp = trim($exp);
        if ($exp === '') {
            return false;
        }
        if (strlen($exp) > 500) {
            return false;
        }
        if (strpos($exp, ';') !== false) {
            return false;
        }
        if (strpos($exp, '--') !== false) {
            return false;
        }
        if (strpos($exp, '/*') !== false) {
            return false;
        }
        if (strpos($exp, '*/') !== false) {
            return false;
        }
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|RENAME|LOAD_FILE|LOAD|HANDLER|LOCK|UNLOCK|SHUTDOWN|FLUSH|KILL|CALL|EXEC|EXECUTE|UNION|BENCHMARK|SLEEP|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA)\b/i', $exp)) {
            return false;
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $exp)) {
            return false;
        }

        return true;
    }

    /**
     * Valida una expresión SQL manual de WHERE/GROUP/ORDER. Permite SELECT (para subconsultas
     * tipo `col NOT IN (SELECT ...)`), pero sigue bloqueando escrituras, comandos peligrosos,
     * comentarios y múltiples statements.
     */
    private function validarExpresionFiltro(string $exp): bool
    {
        $exp = trim($exp);
        if ($exp === '') {
            return false;
        }
        if (strlen($exp) > 2000) {
            return false;
        }
        if (strpos($exp, ';') !== false) {
            return false;
        }
        if (strpos($exp, '--') !== false) {
            return false;
        }
        if (strpos($exp, '/*') !== false) {
            return false;
        }
        if (strpos($exp, '*/') !== false) {
            return false;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|RENAME|LOAD_FILE|LOAD|HANDLER|LOCK|UNLOCK|SHUTDOWN|FLUSH|KILL|CALL|EXEC|EXECUTE|BENCHMARK|SLEEP|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL\.)\b/i', $exp)) {
            return false;
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $exp)) {
            return false;
        }

        if (preg_match('/\bSELECT\b/i', $exp)) {
            $depth = 0;
            $maxDepth = 0;
            for ($i = 0, $n = strlen($exp); $i < $n; $i++) {
                $ch = $exp[$i];
                if ($ch === '(') {
                    $depth++;
                    if ($depth > $maxDepth) {
                        $maxDepth = $depth;
                    }
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth < 0) {
                        return false;
                    }
                }
            }
            if ($depth !== 0) {
                return false;
            }
            if ($maxDepth < 1) {
                return false;
            }
        }

        return true;
    }

    /** Equivalente a $db->escape() de Dolibarr: escapado sin comillas envolventes. */
    private function escape(string $value): string
    {
        $quoted = DB::connection(self::CONNECTION)->getPdo()->quote($value);

        return substr($quoted, 1, -1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Variables de posición $_WHEREn para tipoConsulta="sql" (informe_dinamico.js)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve payload.where[] contra los valores de $entradas para tipoConsulta="sql":
     * mismo criterio que construirWhereConValores() del JS — vacío/omitido desactiva el
     * filtro, arreglo => IN/NOT IN, fecha_rango => >=/<= (23:59:59 en el "hasta"), fecha
     * simple con "=" => cubre el dia completo. El placeholder de cada entrada usa el mismo
     * buildPlaceholder() que ya expone obtenerConsulta()/listarConsultas() (ej. ":fecha_desde"),
     * no el sqlVar interno de Dolibarr ($_WHERE1) que la App nunca ve.
     */
    private function resolverWhereEntradasParaSql(array $whereAll, array $entradas): array
    {
        $out = [];

        foreach ($whereAll as $i => $w) {
            if (empty($w['esEntrada'])) {
                $out[] = $w;

                continue;
            }

            $key = ltrim($this->buildPlaceholder($w['entradaTitulo'] ?? '', (int) $i), ':');
            $valorCrudo = $entradas[$key] ?? null;
            $tipo = $w['entradaTipo'] ?? 'texto';

            if ($tipo === 'fecha_rango') {
                $desde = '';
                $hasta = '';
                if (is_array($valorCrudo)) {
                    $desde = trim((string) ($valorCrudo['desde'] ?? ''));
                    $hasta = trim((string) ($valorCrudo['hasta'] ?? ''));
                } else {
                    $desde = trim((string) $valorCrudo);
                }

                if ($desde === '' && $hasta === '') {
                    $out[] = array_merge($w, ['activo' => false]);

                    continue;
                }
                if ($desde !== '') {
                    $out[] = array_merge($w, ['operador' => '>=', 'valor' => $desde]);
                }
                if ($hasta !== '') {
                    $out[] = array_merge($w, [
                        'operador' => '<=',
                        'valor' => $hasta.' 23:59:59',
                        'conj' => $desde !== '' ? 'AND' : ($w['conj'] ?? 'AND'),
                    ]);
                }

                continue;
            }

            $esArr = is_array($valorCrudo);
            $v = $esArr
                ? array_values(array_filter($valorCrudo, fn ($x) => trim((string) $x) !== ''))
                : $valorCrudo;
            $vacio = $esArr ? empty($v) : ($v === null || trim((string) $v) === '');

            if ($vacio) {
                $out[] = array_merge($w, ['activo' => false]);

                continue;
            }

            if ($esArr) {
                $opIn = in_array($w['operador'] ?? '=', ['!=', 'NOT LIKE'], true) ? 'NOT IN' : 'IN';
                $out[] = array_merge($w, ['operador' => $opIn, 'valor' => $v]);

                continue;
            }

            if ($tipo === 'fecha' && ($w['operador'] ?? '=') === '=' && empty($w['manual'])) {
                $out[] = array_merge($w, ['operador' => '>=', 'valor' => $v]);
                $out[] = array_merge($w, ['operador' => '<=', 'valor' => $v.' 23:59:59', 'conj' => 'AND']);

                continue;
            }

            $out[] = array_merge($w, ['valor' => $v]);
        }

        return $out;
    }

    /**
     * Sustituye cada $_WHEREn presente en el SQL por la condicion de los items de
     * payload.where[] cuyo sqlVar la referencia (varios se combinan con su conj);
     * si la variable queda sin condicion (entrada vacia) se resuelve a "1=1" cuando
     * va "sola" (tras WHERE/AND/OR/ON/HAVING/() o se elimina limpiamente si iba
     * encadenada. Los items cuyo sqlVar no aparece en el SQL van a "resto" para
     * inyectarse en el WHERE general via inyectarWhereEnSql().
     */
    private function sustituirVariablesEnSql(string $sql, array $activos): array
    {
        $porVar = [];
        $resto = [];

        foreach ($activos as $w) {
            $vars = $w['sqlVar'] ?? [];
            if (! is_array($vars)) {
                $vars = ($vars !== null && $vars !== '') ? [$vars] : [];
            }
            $vars = array_values(array_filter($vars, function ($v) use ($sql) {
                return is_string($v)
                    && preg_match('/^\$_WHERE\d+$/', $v)
                    && preg_match('/'.preg_quote($v, '/').'(?!\d)/', $sql);
            }));

            if (! empty($vars)) {
                foreach ($vars as $v) {
                    $porVar[$v][] = $w;
                }
            } else {
                $resto[] = $w;
            }
        }

        if (! preg_match_all('/\$_WHERE\d+/', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return ['sql' => $sql, 'resto' => $resto];
        }

        $out = '';
        $cursor = 0;
        foreach ($matches[0] as [$varName, $offset]) {
            $out .= substr($sql, $cursor, $offset - $cursor);

            $grupo = $porVar[$varName] ?? [];
            $cond = ! empty($grupo) ? $this->condicionesWhereASql($grupo, $sql) : '';

            $antes = rtrim(substr($sql, 0, $offset));
            $sola = $antes === '' || preg_match('/(?:\bWHERE|\bAND|\bOR|\bON|\bHAVING|\()$/i', $antes);

            if ($sola) {
                $out .= $cond !== '' ? $cond : '1=1';
            } else {
                $out .= $cond !== '' ? ('AND '.$cond) : '';
            }

            $cursor = $offset + strlen($varName);
        }
        $out .= substr($sql, $cursor);

        return ['sql' => $out, 'resto' => $resto];
    }

    /** Convierte items del WHERE a una condicion SQL, resolviendo tabla -> alias del propio SQL. */
    private function condicionesWhereASql(array $items, string $sql): string
    {
        $mapAlias = $this->mapaAliasDeSql($sql);
        $parts = [];

        foreach ($items as $w) {
            if (! empty($w['manual'])) {
                $c = '('.($w['expresion'] ?? '1=1').')';
            } else {
                if (empty($w['tabla']) || empty($w['campo'])) {
                    continue;
                }
                $ref = $mapAlias[$w['tabla']] ?? $w['tabla'];
                $colRef = "`{$ref}`.`{$w['campo']}`";
                $op = $w['operador'] ?? '=';

                if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $c = "{$colRef} {$op}";
                } elseif ($op === 'IN' || $op === 'NOT IN') {
                    $valorRaw = $w['valor'] ?? '';
                    $vals = is_array($valorRaw) ? $valorRaw : array_map('trim', explode(',', (string) $valorRaw));
                    $vals = array_values(array_filter($vals, fn ($x) => trim((string) $x) !== ''));
                    if (empty($vals)) {
                        continue;
                    }
                    $listado = implode(', ', array_map(fn ($x) => "'".$this->escape((string) $x)."'", $vals));
                    $c = "{$colRef} {$op} ({$listado})";
                } else {
                    $c = "{$colRef} {$op} '".$this->escape((string) ($w['valor'] ?? ''))."'";
                }
            }

            $conj = strtoupper((string) ($w['conj'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';
            $parts[] = (empty($parts) ? '' : " {$conj} ").$c;
        }

        return empty($parts) ? '' : '('.implode('', $parts).')';
    }

    /** Mapa tabla fisica -> alias usado en el SQL (primera aparicion en FROM/JOIN gana). */
    private function mapaAliasDeSql(string $sql): array
    {
        $map = [];
        $reservadas = ['ON', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'FULL', 'CROSS', 'JOIN', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'UNION', 'USING', 'AND', 'OR', 'AS', 'SET'];

        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z0-9_]+)`?(?:\s+(?:AS\s+)?`?([A-Za-z0-9_]+)`?)?/i', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $t = $m[1];
                $al = $m[2] ?? '';
                if ($al !== '' && in_array(strtoupper($al), $reservadas, true)) {
                    $al = '';
                }
                if (! array_key_exists($t, $map)) {
                    $map[$t] = $al !== '' ? $al : $t;
                }
            }
        }

        return $map;
    }

    /**
     * Corta el SQL en la primera ocurrencia a nivel superior (fuera de parentesis/backticks/
     * strings) de la palabra clave dada. Usado para reordenar WHERE/GROUP BY/HAVING/ORDER BY/
     * LIMIT al inyectar una condicion nueva en inyectarWhereEnSql().
     *
     * @return array{antes: string, despues: string}
     */
    private function cortarPorKeyword(string $s, string $kw): array
    {
        $kwPattern = '/^'.str_replace(' ', '\\s+', preg_quote($kw, '/')).'\b/i';
        $len = strlen($s);
        $depth = 0;
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
                $i++;

                continue;
            }
            if ($c === ')') {
                $depth--;
                $i++;

                continue;
            }
            if ($c === '`') {
                $e = strpos($s, '`', $i + 1);
                if ($e === false) {
                    break;
                }
                $i = $e + 1;

                continue;
            }
            if ($c === "'") {
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    if ($s[$j] === "'") {
                        break;
                    }
                    $j++;
                }
                $i = $j + 1;

                continue;
            }
            if ($depth === 0 && preg_match('/\s/', $c)) {
                if (preg_match($kwPattern, substr($s, $i + 1), $m)) {
                    return [
                        'antes' => trim(substr($s, 0, $i)),
                        'despues' => trim(substr($s, $i + 1 + strlen($m[0]))),
                    ];
                }
            }
            $i++;
        }

        return ['antes' => $s, 'despues' => ''];
    }

    /** Inserta la condicion del WHERE general en el SQL, reordenando GROUP BY/HAVING/ORDER BY/LIMIT. */
    private function inyectarWhereEnSql(string $sql, string $cond): string
    {
        if ($cond === '') {
            return $sql;
        }

        $s = trim(preg_replace('/;+\s*$/', '', trim($sql)));

        $cutLimit = $this->cortarPorKeyword($s, 'LIMIT');
        $s = $cutLimit['antes'];
        $limitPart = $cutLimit['despues'];

        $cutOrder = $this->cortarPorKeyword($s, 'ORDER BY');
        $s = $cutOrder['antes'];
        $orderPart = $cutOrder['despues'];

        $cutHaving = $this->cortarPorKeyword($s, 'HAVING');
        $s = $cutHaving['antes'];
        $havingPart = $cutHaving['despues'];

        $cutGroup = $this->cortarPorKeyword($s, 'GROUP BY');
        $s = $cutGroup['antes'];
        $groupPart = $cutGroup['despues'];

        $cutWhere = $this->cortarPorKeyword($s, 'WHERE');
        $s = $cutWhere['antes'];
        $wherePart = $cutWhere['despues'];

        $out = $s."\nWHERE ".$cond;
        if ($wherePart !== '') {
            $out .= ' AND ('.$wherePart.')';
        }
        if ($groupPart !== '') {
            $out .= "\nGROUP BY ".$groupPart;
        }
        if ($havingPart !== '') {
            $out .= "\nHAVING ".$havingPart;
        }
        if ($orderPart !== '') {
            $out .= "\nORDER BY ".$orderPart;
        }
        if ($limitPart !== '') {
            $out .= "\nLIMIT ".$limitPart;
        }

        return $out;
    }

    /**
     * Las consultas dinámicas guardadas (algunas generadas con IA) suelen tener GROUP BY que
     * no cubren todas las columnas no agregadas del SELECT. Bajo la conexión mysqli propia de
     * Dolibarr eso corre igual (su sql_mode no incluye ONLY_FULL_GROUP_BY); la conexión 'mysql'
     * de Laravel sí lo activa vía 'strict' => true en config/database.php. Se quita solo para
     * la sesión de esta conexión (no toca el sql_mode global ni el resto de la app) para que
     * el comportamiento de ejecución coincida con el de Dolibarr.
     */
    private function relajarOnlyFullGroupBy(): void
    {
        DB::connection(self::CONNECTION)->statement(
            "SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY', '')"
        );
    }
}
