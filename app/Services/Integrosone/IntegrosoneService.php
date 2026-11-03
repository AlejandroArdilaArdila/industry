<?php

namespace App\Services\Integrosone;

use App\Models\Categoria;
use App\Models\PrecioEventoProveedor;
use App\Models\UsuarioProveedor;
use App\Services\OrdenVenta\PreordenService;
use App\Support\DolibarrCrypt;
use Illuminate\Http\Request;

class IntegrosoneService
{
    public function __construct(private PreordenService $preordenService) {}


    /**
     * Login de un contacto de proveedor con su correo y su clave.
     * Recorre los accesos que coinciden y entra con el primero cuya clave
     * verifica (igual que el legado con dol_verifyHash); devuelve la APIKEY
     * descifrada y los datos del contacto y del proveedor.
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function loginProveedor(string $correo, string $clave): array
    {
        $accesos = UsuarioProveedor::query()
            ->with(['contacto', 'proveedor'])
            ->habilitadosPorCorreo($correo)
            ->orderBy('rowid')
            ->get();

        /** @var UsuarioProveedor|null $acceso */
        $acceso = $accesos->first(
            fn (UsuarioProveedor $a) => $this->verificarHash($clave, (string) $a->getAttribute('pass_crypted'))
        );

        if (! $acceso) {
            return [401, ['error' => 'CREDENCIALES INVALIDAS']];
        }

        $apiKey = DolibarrCrypt::decrypt((string) $acceso->getAttribute('api_key'));

        if ($apiKey === null || $apiKey === '') {
            return [403, ['error' => 'USUARIO NO TIENE API_KEY CONFIGURADA']];
        }

        $contacto = $acceso->contacto;
        $proveedor = $acceso->proveedor;

        return [200, [
            'APIKEY' => $apiKey,
            'acceso_id' => (int) $acceso->rowid,
            'debe_cambiar_clave' => (bool) $acceso->debe_cambiar_clave,
            'contacto' => [
                'id' => (int) $acceso->fk_socpeople,
                'nombres' => $contacto->firstname,
                'apellidos' => $contacto->lastname,
                'email' => $contacto->email,
            ],
            'proveedor' => [
                'id' => (int) $acceso->fk_soc,
                'nit' => $proveedor->siren,
                'razon_social' => $proveedor->nom,
            ],
        ]];
    }

    /**
     * Productos con precio por evento de un proveedor, ordenados por
     * descripcion. La DOLAPIKEY debe ser la de un acceso habilitado de ese
     * mismo proveedor: la de otro proveedor (o una desconocida) no sirve.
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function productosEventoProveedor(?string $apiKey, int $idProveedor): array
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return [401, ['error' => 'FALTA DOLAPIKEY']];
        }

        if (! $this->apiKeyPerteneceAlProveedor(trim($apiKey), $idProveedor)) {
            return [401, ['error' => 'DOLAPIKEY INVALIDA PARA ESTE PROVEEDOR']];
        }

        $filas = PrecioEventoProveedor::query()->resumenDeProveedor($idProveedor)->get();

        $categoriasPorProducto = Categoria::query()
            ->deProductos($filas->pluck('id_producto')->all())
            ->get()
            ->groupBy('id_producto')
            ->map(fn ($categorias) => $categorias
                ->map(fn (Categoria $c) => ['id' => (int) $c->getAttribute('id'), 'nombre' => $c->getAttribute('nombre')])
                ->values()
                ->all());

        $productos = $filas
            ->map(fn (PrecioEventoProveedor $f) => [
                'id_producto' => (int) $f->getAttribute('id_producto'),
                'referencia' => $f->getAttribute('referencia'),
                'descripcion' => $f->getAttribute('descripcion'),
                'codigos_barras' => $f->getAttribute('codigos_barras'),
                'categorias' => $categoriasPorProducto->get((int) $f->getAttribute('id_producto'), []),
                'stock' => $this->decimal($f->getAttribute('stock')),
                'embalaje' => $this->decimal($f->getAttribute('embalaje')),
                'costo_evento_unidad' => $this->decimal($f->getAttribute('costo_evento_unidad')),
                'precio_base_sin_iva' => $this->decimal($f->getAttribute('precio_base_sin_iva')),
                'pct_iva' => $this->decimal($f->getAttribute('pct_iva')),
                'pct_desc_proveedor' => $this->decimal($f->getAttribute('pct_desc_proveedor')),
                'total_costo_con_iva' => $this->decimal($f->getAttribute('total_costo_con_iva')),
                'costo_neto_sin_iva' => $this->decimal($f->getAttribute('costo_neto_sin_iva')),
                'precio_afiliado_con_iva' => $this->decimal($f->getAttribute('precio_afiliado_con_iva')),
                'precio_afiliado_sin_iva' => $this->decimal($f->getAttribute('precio_afiliado_sin_iva')),
                'ganancia_sin_iva' => $this->decimal($f->getAttribute('ganancia_sin_iva')),
                'pct_ganancia' => $this->decimal($f->getAttribute('pct_ganancia')),
                'pct_desc_cliente_sin_iva' => $this->decimal($f->getAttribute('pct_desc_cliente_sin_iva')),
                'estado' => $f->getAttribute('estado'),
                'ultima_fecha' => $f->getAttribute('ultima_fecha'),
            ])
            ->values();

        return [200, [
            'total' => $productos->count(),
            'productos' => $productos->all(),
        ]];
    }

    /**
     * Categorias distintas de los productos con precio por evento de un
     * proveedor, con cuantos de esos productos tiene cada una. Misma
     * validacion de DOLAPIKEY que productosEventoProveedor().
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function categoriasEventoProveedor(?string $apiKey, int $idProveedor): array
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return [401, ['error' => 'FALTA DOLAPIKEY']];
        }

        if (! $this->apiKeyPerteneceAlProveedor(trim($apiKey), $idProveedor)) {
            return [401, ['error' => 'DOLAPIKEY INVALIDA PARA ESTE PROVEEDOR']];
        }

        $idsProducto = PrecioEventoProveedor::query()
            ->where('fk_soc', $idProveedor)
            ->pluck('fk_product')
            ->unique()
            ->values()
            ->all();

        $categorias = Categoria::query()
            ->deProductos($idsProducto)
            ->get()
            ->groupBy('id')
            ->map(fn ($filas) => [
                'id' => (int) $filas->first()->getAttribute('id'),
                'nombre' => $filas->first()->getAttribute('nombre'),
                'total_productos' => $filas->pluck('id_producto')->unique()->count(),
            ])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [200, [
            'total' => $categorias->count(),
            'categorias' => $categorias->all(),
        ]];
    }

    /**
     * Crea una pre-orden (llx_integros_precommande) a nombre del cliente
     * indicado en 'customer_id' del body (obligatorio; ver
     * PreordenService::crearParaProveedor()). idProveedor solo identifica al
     * proveedor autenticado, no se usa como fk_soc. Misma validacion de
     * DOLAPIKEY que productosEventoProveedor(). No aplica 'siren' ni
     * 'employeeId' del body (esos son del flujo de PreordenService::crear()
     * para usuarios internos).
     *
     * @return array{0: int, 1: array<string,mixed>} tupla [status http, body]
     */
    public function crearPreordenProveedor(?string $apiKey, int $idProveedor, Request $request): array
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return [401, ['error' => 'FALTA DOLAPIKEY']];
        }

        if (! $this->apiKeyPerteneceAlProveedor(trim($apiKey), $idProveedor)) {
            return [401, ['error' => 'DOLAPIKEY INVALIDA PARA ESTE PROVEEDOR']];
        }

        return [200, $this->preordenService->crearParaProveedor($request, $idProveedor)];
    }

    /**
     * La api_key de llx_integros_usuario_proveedor va cifrada (dolcrypt), asi
     * que no se busca por igualdad en SQL: se descifran solo los accesos
     * habilitados del proveedor pedido y se compara en tiempo constante.
     */
    private function apiKeyPerteneceAlProveedor(string $apiKey, int $idProveedor): bool
    {
        return UsuarioProveedor::query()
            ->habilitados()
            ->where('fk_soc', $idProveedor)
            ->get(['rowid', 'api_key'])
            ->contains(function (UsuarioProveedor $acceso) use ($apiKey) {
                $guardada = DolibarrCrypt::decrypt((string) $acceso->getAttribute('api_key'));

                return $guardada !== null && $guardada !== '' && hash_equals($guardada, $apiKey);
            });
    }

    /**
     * Igual que apiKeyPerteneceAlProveedor() pero sin restringir a un
     * proveedor concreto: valida que la DOLAPIKEY sea la de CUALQUIER acceso
     * de proveedor habilitado. Para endpoints que solo necesitan saber "es
     * un proveedor valido", no de cual.
     */
    public function accesoProveedorValido(?string $apiKey): bool
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return false;
        }

        $apiKey = trim($apiKey);

        return UsuarioProveedor::query()
            ->habilitados()
            ->get(['rowid', 'api_key'])
            ->contains(function (UsuarioProveedor $acceso) use ($apiKey) {
                $guardada = DolibarrCrypt::decrypt((string) $acceso->getAttribute('api_key'));

                return $guardada !== null && $guardada !== '' && hash_equals($guardada, $apiKey);
            });
    }

    /**
     * Equivale a dol_verifyHash() de Dolibarr con MAIN_SECURITY_HASH_ALGO=password_hash:
     * autodetecta el algoritmo por la forma del hash (bcrypt/argon "$...", md5 de 32
     * caracteres o sha1(md5) de 40), para aceptar tambien claves guardadas antes de
     * migrar el algoritmo.
     */
    private function verificarHash(string $clave, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        if ($hash[0] === '$') {
            return password_verify($clave, $hash);
        }

        return match (strlen($hash)) {
            32 => hash_equals($hash, md5($clave)),
            40 => hash_equals($hash, sha1(md5($clave))),
            default => false,
        };
    }

    /** Numero como float (null si viene null), no como string, para que el cliente no parsee. */
    private function decimal(mixed $valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }
}
