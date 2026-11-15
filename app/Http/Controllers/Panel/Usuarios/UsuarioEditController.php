<?php

namespace App\Http\Controllers\Panel\Usuarios;

use App\Http\Controllers\Controller;
use App\Models\DolibarrUser;
use App\Services\Panel\FichaExtrafields;
use App\Services\Usuarios\PerfilUsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Modal de ficha de un usuario Dolibarr (asistente por pasos, solo admin).
 * show() devuelve el HTML del modal; update() guarda los pasos de datos y
 * los demás pasos (contraseña, grupos, permisos) tienen su propia ruta.
 */
class UsuarioEditController extends Controller
{
    /** Campos de user que guarda cada paso de update(). */
    private const SECCIONES = [
        'datos' => ['firstname', 'lastname', 'civility', 'gender', 'birth', 'email', 'job', 'office_phone', 'user_mobile'],
        'ubicacion' => ['address', 'town', 'zip', 'fk_state', 'fk_country'],
        'acceso' => ['login', 'statut', 'admin', 'employee'],
        'adicionales' => [],
    ];

    private const MENSAJES = [
        'required' => 'El campo :attribute es obligatorio.',
        'email' => 'Escribe un correo válido.',
        'max' => 'El campo :attribute no puede superar :max caracteres.',
        'min' => 'El campo :attribute debe tener al menos :min caracteres.',
        'date' => 'El campo :attribute no es una fecha válida.',
        'integer' => 'El campo :attribute debe ser un número entero.',
        'regex' => 'El campo :attribute solo admite letras, números y . _ @ -',
        'unique' => 'Ese :attribute ya está en uso.',
        'in' => 'El valor de :attribute no es válido.',
        'confirmed' => 'La confirmación de la contraseña no coincide.',
    ];

    private const ATRIBUTOS = [
        'firstname' => 'nombres', 'lastname' => 'apellidos', 'email' => 'correo', 'job' => 'cargo',
        'birth' => 'fecha de nacimiento', 'login' => 'usuario', 'password' => 'contraseña',
    ];

    public function __construct(
        private PerfilUsuarioService $perfilService,
        private FichaExtrafields $extrafields,
    ) {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403, 'Solo un administrador puede editar otros usuarios.');
    }

    /** La antigua página de edición abre ahora el modal sobre el listado. */
    public function edit(DolibarrUser $usuario): RedirectResponse
    {
        return redirect()->route('panel.usuarios.index', ['editar' => $usuario->rowid]);
    }

    public function show(DolibarrUser $usuario): JsonResponse
    {
        $db = DB::connection('mysql');

        $html = view('panel.usuarios.partials._ficha', [
            'usuario' => $usuario,
            'puedeEditar' => true,
            'esNuevo' => false,
            'esUnoMismo' => (int) $usuario->rowid === (int) Auth::guard('web')->user()->rowid,
            'paises' => $this->perfilService->paises()->map(fn ($p) => ['id' => (int) $p->rowid, 'label' => $p->label])->values(),
            'departamentos' => $this->perfilService->departamentos()->map(fn ($d) => ['id' => (int) $d->rowid, 'label' => $d->nom])->values(),
            'civilidades' => $this->perfilService->civilidades(),
            'extrafields' => $this->extrafields->conValores('user', 'user_extrafields', (int) $usuario->rowid),
            'grupos' => $this->perfilService->grupos(),
            'gruposDe' => $this->perfilService->gruposDe($usuario),
            'permisosAgrupados' => $this->perfilService->permisosAgrupados(),
            'permisosDe' => $this->perfilService->permisosDe($usuario),
            'permisosPorGrupo' => $db->table('usergroup_rights as gr')
                ->join('usergroup_user as gu', 'gu.fk_usergroup', '=', 'gr.fk_usergroup')
                ->where('gu.fk_user', $usuario->rowid)
                ->distinct()
                ->pluck('gr.fk_id')
                ->map(fn ($v) => (int) $v)
                ->all(),
        ])->render();

        return response()->json(['html' => $html]);
    }

    public function update(Request $request, DolibarrUser $usuario): JsonResponse
    {
        $request->validate(['seccion' => ['required', Rule::in(array_keys(self::SECCIONES))]]);
        $seccion = $request->input('seccion');

        if ($seccion === 'adicionales') {
            $request->validate(
                $this->extrafields->reglas('user', fn () => true),
                self::MENSAJES,
                $this->extrafields->atributos('user')
            );
            $this->extrafields->guardar('user', 'user_extrafields', (int) $usuario->rowid, $request->input('ef', []), fn () => true);

            return $this->respuesta($usuario, null);
        }

        $reglas = array_intersect_key(
            $this->perfilService->reglasDatosPersonales(true, $usuario->rowid),
            array_flip(self::SECCIONES[$seccion])
        );
        if ($seccion === 'acceso') {
            $reglas['login'] = [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._@\-]+$/',
                Rule::unique('mysql.user', 'login')->ignore($usuario->rowid, 'rowid')->where('entity', $usuario->entity),
            ];
        }
        $datos = $request->validate($reglas, self::MENSAJES, self::ATRIBUTOS);

        // Un admin no puede quitarse a sí mismo el acceso desde aquí.
        if ($seccion === 'acceso' && (int) $usuario->rowid === (int) Auth::guard('web')->user()->rowid) {
            if ((int) $datos['statut'] !== 1 || (int) $datos['admin'] !== 1) {
                throw ValidationException::withMessages([
                    'admin' => 'No puedes desactivarte ni quitarte el rol de administrador a ti mismo.',
                ]);
            }
        }

        $this->perfilService->actualizarDatosPersonales($usuario, $datos, true);

        return $this->respuesta($usuario->refresh(), null);
    }

    public function updatePassword(Request $request, DolibarrUser $usuario): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], self::MENSAJES, self::ATRIBUTOS);

        $this->perfilService->cambiarPassword($usuario, $request->input('password'));

        return $this->respuesta($usuario, 'Contraseña actualizada');
    }

    public function updateGrupos(Request $request, DolibarrUser $usuario): JsonResponse
    {
        $this->perfilService->sincronizarGrupos($usuario, $request->input('grupos', []));

        return $this->respuesta($usuario, 'Grupos actualizados');
    }

    public function updatePermisos(Request $request, DolibarrUser $usuario): JsonResponse
    {
        $this->perfilService->sincronizarPermisos($usuario, $request->input('permisos', []));

        return $this->respuesta($usuario, 'Permisos actualizados');
    }

    private function respuesta(DolibarrUser $usuario, ?string $mensaje): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $mensaje,
            'usuario' => [
                'rowid' => (int) $usuario->rowid,
                'nombre' => $usuario->full_name ?: $usuario->login,
                'login' => (string) $usuario->login,
                'statut' => (string) $usuario->statut,
                'admin' => (string) $usuario->admin,
            ],
        ]);
    }
}
