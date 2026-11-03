<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Corre contra SQLite en memoria (phpunit.xml): crea a mano las tablas
 * minimas que toca el endpoint, nunca la BD real.
 */
class IntegrosoneProductosProveedorTest extends TestCase
{
    private const CLAVE_INSTANCIA = 'clave-de-instancia-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.dolibarr.instance_key' => self::CLAVE_INSTANCIA]);

        Schema::create('societe', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->string('nom');
            $t->string('siren')->nullable();
            $t->integer('fournisseur')->default(0);
            $t->integer('status')->default(1);
        });

        Schema::create('socpeople', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->integer('fk_soc');
            $t->integer('statut')->default(1);
        });

        Schema::create('integros_usuario_proveedor', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->integer('fk_socpeople');
            $t->integer('fk_soc');
            $t->string('pass_crypted')->nullable();
            $t->text('api_key')->nullable();
            $t->integer('estado')->default(1);
        });

        Schema::create('product', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->string('ref');
            $t->string('label');
            $t->decimal('price', 12, 2)->default(0);
        });

        Schema::create('product_extrafields', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->integer('fk_object');
            $t->string('codigo_barras')->nullable();
        });

        Schema::create('integros_precio_evento_proveedor', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->integer('fk_soc');
            $t->integer('fk_product');
            $t->string('embalaje')->nullable();
            $t->decimal('costo_evento', 12, 2)->default(0);
            $t->decimal('iva', 5, 2)->default(0);
            $t->decimal('descuento', 5, 2)->default(0);
            $t->decimal('otros_impuestos', 12, 2)->default(0);
            $t->decimal('total_costo', 12, 2)->default(0);
            $t->decimal('precio_afiliado', 12, 2)->default(0);
            $t->integer('estado')->default(1);
            $t->string('fecha_creacion')->nullable();
            $t->string('fecha_modif')->nullable();
        });

        DB::table('societe')->insert([
            ['rowid' => 1, 'nom' => 'Proveedor Uno SAS', 'siren' => '900111222', 'fournisseur' => 1, 'status' => 1],
            ['rowid' => 2, 'nom' => 'Proveedor Dos SAS', 'siren' => '900333444', 'fournisseur' => 1, 'status' => 1],
            ['rowid' => 3, 'nom' => 'Proveedor Cerrado', 'siren' => '900555666', 'fournisseur' => 1, 'status' => 0],
        ]);

        DB::table('socpeople')->insert([
            ['rowid' => 10, 'fk_soc' => 1, 'statut' => 1],
            ['rowid' => 11, 'fk_soc' => 1, 'statut' => 0],
            ['rowid' => 20, 'fk_soc' => 2, 'statut' => 1],
            ['rowid' => 30, 'fk_soc' => 3, 'statut' => 1],
        ]);

        // [rowid, fk_socpeople, fk_soc, api_key, estado]
        foreach ([
            [1, 10, 1, $this->cifrar('KEY-PROVEEDOR-1'), 1],
            [2, 11, 1, $this->cifrar('KEY-CONTACTO-INACTIVO'), 1],
            [3, 10, 1, $this->cifrar('KEY-ACCESO-INACTIVO'), 0],
            [4, 20, 2, 'KEY-PROVEEDOR-2-EN-CLARO', 1],
            [5, 30, 3, $this->cifrar('KEY-PROVEEDOR-CERRADO'), 1],
        ] as [$id, $contacto, $soc, $apiKey, $estado]) {
            DB::table('integros_usuario_proveedor')->insert([
                'rowid' => $id, 'fk_socpeople' => $contacto, 'fk_soc' => $soc,
                'pass_crypted' => 'x', 'api_key' => $apiKey, 'estado' => $estado,
            ]);
        }

        DB::table('product')->insert([
            ['rowid' => 100, 'ref' => 'ZZZ-1', 'label' => 'ZUCARITAS', 'price' => 5000],
            ['rowid' => 101, 'ref' => 'ARR-1', 'label' => 'ARROZ 500G', 'price' => 2100],
            ['rowid' => 102, 'ref' => 'LEN-1', 'label' => 'LENTEJA 500G', 'price' => 3200],
            ['rowid' => 103, 'ref' => 'OTRO-1', 'label' => 'PRODUCTO DEL PROVEEDOR DOS', 'price' => 900],
        ]);

        DB::table('product_extrafields')->insert([
            ['rowid' => 1, 'fk_object' => 100, 'codigo_barras' => '["7701111111111"]'],
            ['rowid' => 2, 'fk_object' => 101, 'codigo_barras' => '["7702222222222"]'],
            // El producto 102 no tiene extrafield: el LEFT JOIN debe dejarlo con codigo_barras null.
        ]);

        // El orden de insercion no es el orden por descripcion.
        DB::table('integros_precio_evento_proveedor')->insert([
            ['rowid' => 1, 'fk_soc' => 1, 'fk_product' => 100, 'embalaje' => 'CAJA X 12', 'costo_evento' => 4000, 'iva' => 19, 'descuento' => 5, 'otros_impuestos' => 0, 'total_costo' => 4560, 'precio_afiliado' => 4800, 'estado' => 1, 'fecha_creacion' => '2026-09-01 10:00:00', 'fecha_modif' => '2026-09-10 08:30:00'],
            ['rowid' => 2, 'fk_soc' => 1, 'fk_product' => 102, 'embalaje' => null, 'costo_evento' => 2500.5, 'iva' => 0, 'descuento' => 0, 'otros_impuestos' => 10, 'total_costo' => 2510.5, 'precio_afiliado' => 3000, 'estado' => 0, 'fecha_creacion' => null, 'fecha_modif' => null],
            ['rowid' => 3, 'fk_soc' => 1, 'fk_product' => 101, 'embalaje' => 'CAJA X 24', 'costo_evento' => 1500, 'iva' => 19, 'descuento' => 5, 'otros_impuestos' => 0, 'total_costo' => 1695, 'precio_afiliado' => 1800, 'estado' => 1, 'fecha_creacion' => '2026-09-01 10:00:00', 'fecha_modif' => '2026-09-10 08:30:00'],
            ['rowid' => 4, 'fk_soc' => 2, 'fk_product' => 103, 'embalaje' => 'UND', 'costo_evento' => 700, 'iva' => 19, 'descuento' => 0, 'otros_impuestos' => 0, 'total_costo' => 833, 'precio_afiliado' => 850, 'estado' => 1, 'fecha_creacion' => null, 'fecha_modif' => null],
        ]);
    }

    /**
     * Replica dolEncrypt(..., 'dolibarr') (IV fijo, formato dolcrypt).
     */
    private function cifrar(string $valor): string
    {
        $iv = substr(md5('dolibarr'), 0, openssl_cipher_iv_length('AES-256-CTR'));

        return 'dolcrypt:AES-256-CTR:'.$iv.':'.openssl_encrypt($valor, 'AES-256-CTR', self::CLAVE_INSTANCIA, 0, $iv);
    }

    private function pedir(int $idProveedor, ?string $apiKey)
    {
        $headers = $apiKey === null ? [] : ['DOLAPIKEY' => $apiKey];

        return $this->getJson("/api/api.php/integrosone/proveedores/{$idProveedor}/productos", $headers);
    }

    public function test_devuelve_los_productos_del_proveedor_ordenados_por_descripcion(): void
    {
        $r = $this->pedir(1, 'KEY-PROVEEDOR-1')->assertOk();

        $r->assertJsonPath('total', 3);

        $this->assertSame(
            ['ARROZ 500G', 'LENTEJA 500G', 'ZUCARITAS'],
            array_column($r->json('productos'), 'descripcion'),
        );
    }

    public function test_cada_producto_trae_los_campos_de_la_consulta_con_codigo_de_barras_limpio(): void
    {
        $arroz = $this->pedir(1, 'KEY-PROVEEDOR-1')->assertOk()->json('productos.0');

        $this->assertEquals([
            'id_proveedor' => 1,
            'nit' => '900111222',
            'proveedor' => 'Proveedor Uno SAS',
            'id_producto' => 101,
            'referencia' => 'ARR-1',
            'descripcion' => 'ARROZ 500G',
            'codigo_barras' => '7702222222222',
            'embalaje' => 'CAJA X 24',
            'costo_evento_antes_iva' => 1500,
            'iva' => 19,
            'descuento' => 5,
            'otros_impuestos' => 0,
            'total_costo_evento' => 1695,
            'precio_afiliado' => 1800,
            'estado' => 1,
            'precio_venta_erp' => 2100,
            'fecha_creacion' => '2026-09-01 10:00:00',
            'fecha_modif' => '2026-09-10 08:30:00',
        ], $arroz);
    }

    public function test_un_producto_sin_extrafield_sale_con_codigo_de_barras_null(): void
    {
        $lenteja = $this->pedir(1, 'KEY-PROVEEDOR-1')->assertOk()->json('productos.1');

        $this->assertSame('LENTEJA 500G', $lenteja['descripcion']);
        $this->assertNull($lenteja['codigo_barras']);
        $this->assertSame(2500.5, $lenteja['costo_evento_antes_iva']);
    }

    public function test_solo_trae_los_productos_del_proveedor_pedido(): void
    {
        $r = $this->pedir(2, 'KEY-PROVEEDOR-2-EN-CLARO')->assertOk();

        $r->assertJsonPath('total', 1)
            ->assertJsonPath('productos.0.descripcion', 'PRODUCTO DEL PROVEEDOR DOS');
    }

    public function test_la_apikey_de_otro_proveedor_no_sirve(): void
    {
        $this->pedir(1, 'KEY-PROVEEDOR-2-EN-CLARO')->assertStatus(401);
        $this->pedir(2, 'KEY-PROVEEDOR-1')->assertStatus(401);
    }

    public function test_sin_apikey_o_con_una_desconocida_da_401(): void
    {
        $this->pedir(1, null)->assertStatus(401);
        $this->pedir(1, 'NO-EXISTE')->assertStatus(401);
        $this->pedir(1, '   ')->assertStatus(401);
    }

    public function test_accesos_contactos_o_proveedores_inactivos_no_sirven(): void
    {
        $this->pedir(1, 'KEY-CONTACTO-INACTIVO')->assertStatus(401);   // socpeople.statut = 0
        $this->pedir(1, 'KEY-ACCESO-INACTIVO')->assertStatus(401);     // acceso estado = 0
        $this->pedir(3, 'KEY-PROVEEDOR-CERRADO')->assertStatus(401);   // societe.status = 0
    }

    public function test_proveedor_sin_precios_por_evento_da_lista_vacia(): void
    {
        DB::table('integros_precio_evento_proveedor')->where('fk_soc', 2)->delete();

        $this->pedir(2, 'KEY-PROVEEDOR-2-EN-CLARO')
            ->assertOk()
            ->assertExactJson(['total' => 0, 'productos' => []]);
    }

    public function test_la_ruta_solo_acepta_ids_numericos(): void
    {
        $this->getJson('/api/api.php/integrosone/proveedores/abc/productos', ['DOLAPIKEY' => 'KEY-PROVEEDOR-1'])
            ->assertStatus(404);
    }
}
