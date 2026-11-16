<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\PlanDiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Modal «Mi día»: sugerencias de acción para gestionar el día. */
class PlanDiaController extends Controller
{
    public function __construct(private PlanDiaService $plan)
    {
    }

    /** ?alcance=mios|equipo (el equipo solo para administradores; por defecto, equipo si es administrador). */
    public function index(Request $request): JsonResponse
    {
        $alcance = $request->query('alcance');

        return response()->json($this->plan->plan(Auth::guard('web')->user(), $alcance === null ? null : $alcance === 'equipo'));
    }
}
