<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Corre contra SQLite en memoria (phpunit.xml): crea a mano las tablas
 * minimas que toca el login, nunca la BD real.
 */
class IntegrosoneProveedorLoginTest extends TestCase
{
    private const LOGIN = '/api/custom/integrosone/proveedores/login';

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
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('email')->nullable();
            $t->integer('statut')->default(1);
        });

        Schema::create('integros_usuario_proveedor', function (Blueprint $t) {
            $t->integer('rowid')->primary();
            $t->integer('fk_socpeople');
            $t->integer('fk_soc');
            $t->string('pass_crypted')->nullable();
            $t->text('api_key')->nullable();
            $t->integer('debe_cambiar_clave')->default(0);
            $t->integer('estado')->default(1);
        });

        DB::table('societe')->insert([
            ['rowid' => 1, 'nom' => 'Proveedor Uno SAS', 'siren' => '900111222', 'fournisseur' => 1, 'status' => 1],
            ['rowid' => 2, 'nom' => 'Proveedor Dos SAS', 'siren' => '900333444', 'fournisseur' => 1, 'status' => 1],
            ['rowid' => 3, 'nom' => 'Solo Cliente', 'siren' => '900555666', 'fournisseur' => 0, 'status' => 1],
            ['rowid' => 4, 'nom' => 'Proveedor Cerrado', 'siren' => '900777888', 'fournisseur' => 1, 'status' => 0],
        ]);

        // [rowid, fk_soc, nombres, apellidos, email, statut]
        foreach ([
            [10, 1, 'Eurides', 'Mapari', 'euridesjmr@gmail.com', 1],
            [11, 1, 'Inactivo', 'X', 'contacto.inactivo@uno.com', 0],
            [12, 3, 'Cliente', 'Y', 'cliente@solo.com', 1],
            [13, 4, 'Cerrado', 'Z', 'cerrado@cerrado.com', 1],
            [14, 1, 'Md5', 'H', 'md5@uno.com', 1],
            [15, 1, 'Sha1', 'H', 'sha1@uno.com', 1],
            [16, 1, 'SinKey', 'K', 'sinkey@uno.com', 1],
            [17, 1, 'AccesoInactivo', 'A', 'accesoinactivo@uno.com', 1],
            [18, 1, 'Doble', 'D', 'doble@x.com', 1],
            [19, 2, 'Doble', 'E', 'doble@x.com', 1],
            [20, 1, 'Cambiar', 'C', 'cambiar@uno.com', 1],
        ] as [$id, $soc, $nombres, $apellidos, $email, $statut]) {
            DB::table('socpeople')->insert([
                'rowid' => $id, 'fk_soc' => $soc, 'firstname' => $nombres,
                'lastname' => $apellidos, 'email' => $email, 'statut' => $statut,
            ]);
        }

        $bcrypt = password_hash('123456', PASSWORD_BCRYPT);

        // [rowid, fk_socpeople, fk_soc, pass_crypted, api_key, debe_cambiar_clave, estado]
        foreach ([
            [1, 10, 1, $bcrypt, $this->cifrar('API-KEY-EURIDES'), 0, 1],
            [2, 11, 1, $bcrypt, $this->cifrar('API-KEY-2'), 0, 1],
            [3, 12, 3, $bcrypt, $this->cifrar('API-KEY-3'), 0, 1],
            [4, 13, 4, $bcrypt, $this->cifrar('API-KEY-4'), 0, 1],
            [5, 14, 1, md5('123456'), 'api-key-en-claro', 0, 1],
            [6, 15, 1, sha1(md5('123456')), $this->cifrar('API-KEY-SHA1'), 0, 1],
            [7, 16, 1, $bcrypt, null, 0, 1],
            [8, 17, 1, $bcrypt, $this->cifrar('API-KEY-8'), 0, 0],
            // Mismo correo en dos proveedores: gana el primero cuya clave verifica.
            [9, 18, 1, password_hash('otra-clave', PASSWORD_BCRYPT), $this->cifrar('API-KEY-DOBLE-1'), 0, 1],
            [10, 19, 2, $bcrypt, $this->cifrar('API-KEY-DOBLE-2'), 0, 1],
            [11, 20, 1, $bcrypt, $this->cifrar('API-KEY-CAMBIAR'), 1, 1],
        ] as [$id, $contacto, $soc, $hash, $apiKey, $cambiar, $estado]) {
            DB::table('integros_usuario_proveedor')->insert([
                'rowid' => $id, 'fk_socpeople' => $contacto, 'fk_soc' => $soc, 'pass_crypted' => $hash,
                'api_key' => $apiKey, 'debe_cambiar_clave' => $cambiar, 'estado' => $estado,
            ]);
        }
    }

    /**
     * Replica dolEncrypt(..., 'dolibarr') (IV fijo, formato dolcrypt).
     */
    private function cifrar(string $valor): string
    {
        $iv = substr(md5('dolibarr'), 0, openssl_cipher_iv_length('AES-256-CTR'));

        return 'dolcrypt:AES-256-CTR:'.$iv.':'.openssl_encrypt($valor, 'AES-256-CTR', self::CLAVE_INSTANCIA, 0, $iv);
    }

    public function test_entra_con_correo_y_clave_y_devuelve_apikey_y_datos(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'euridesjmr@gmail.com', 'password' => '123456'])
            ->assertOk()
            ->assertExactJson([
                'APIKEY' => 'API-KEY-EURIDES',
                'acceso_id' => 1,
                'debe_cambiar_clave' => false,
                'contacto' => [
                    'id' => 10,
                    'nombres' => 'Eurides',
                    'apellidos' => 'Mapari',
                    'email' => 'euridesjmr@gmail.com',
                ],
                'proveedor' => [
                    'id' => 1,
                    'nit' => '900111222',
                    'razon_social' => 'Proveedor Uno SAS',
                ],
            ]);
    }

    public function test_acepta_clave_guardada_como_md5_y_sha1md5_y_apikey_sin_cifrar(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'md5@uno.com', 'password' => '123456'])
            ->assertOk()
            ->assertJsonPath('APIKEY', 'api-key-en-claro');

        $this->postJson(self::LOGIN, ['email' => 'sha1@uno.com', 'password' => '123456'])
            ->assertOk()
            ->assertJsonPath('APIKEY', 'API-KEY-SHA1');
    }

    public function test_indica_si_debe_cambiar_la_clave(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'cambiar@uno.com', 'password' => '123456'])
            ->assertOk()
            ->assertJsonPath('debe_cambiar_clave', true);
    }

    public function test_clave_o_correo_incorrectos_dan_401(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'euridesjmr@gmail.com', 'password' => '654321'])->assertStatus(401);
        $this->postJson(self::LOGIN, ['email' => 'nadie@uno.com', 'password' => '123456'])->assertStatus(401);
    }

    public function test_acceso_contacto_o_proveedor_inactivo_o_que_no_es_proveedor_no_entran(): void
    {
        foreach ([
            'accesoinactivo@uno.com',   // integros_usuario_proveedor.estado = 0
            'contacto.inactivo@uno.com', // socpeople.statut = 0
            'cliente@solo.com',          // societe.fournisseur = 0
            'cerrado@cerrado.com',       // societe.status = 0
        ] as $email) {
            $this->postJson(self::LOGIN, ['email' => $email, 'password' => '123456'])->assertStatus(401);
        }
    }

    public function test_con_el_mismo_correo_en_varios_proveedores_entra_por_el_acceso_cuya_clave_verifica(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'doble@x.com', 'password' => '123456'])
            ->assertOk()
            ->assertJsonPath('APIKEY', 'API-KEY-DOBLE-2')
            ->assertJsonPath('proveedor.id', 2);
    }

    public function test_sin_api_key_configurada_da_403(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'sinkey@uno.com', 'password' => '123456'])->assertStatus(403);
    }

    public function test_no_expone_el_hash_ni_la_api_key_cifrada(): void
    {
        $body = $this->postJson(self::LOGIN, ['email' => 'euridesjmr@gmail.com', 'password' => '123456'])->assertOk()->getContent();

        $this->assertStringNotContainsString('pass_crypted', $body);
        $this->assertStringNotContainsString('dolcrypt', $body);
    }

    public function test_faltan_parametros_da_422(): void
    {
        $this->postJson(self::LOGIN, ['email' => 'euridesjmr@gmail.com'])->assertStatus(422);
        $this->postJson(self::LOGIN, ['password' => '123456'])->assertStatus(422);
    }
}
