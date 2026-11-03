<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Integros POS API',
    description: 'Servicios propios que hacen de puente entre la app POS y la base de datos de Dolibarr.'
)]
#[OA\SecurityScheme(
    securityScheme: 'DolApiKey',
    type: 'apiKey',
    in: 'header',
    name: 'DOLAPIKEY',
    description: "Token de API del usuario, tal como aparece en su ficha de usuario en Dolibarr (campo 'Clave API')."
)]
#[OA\SecurityScheme(
    securityScheme: 'SanctumToken',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'access_token',
    description: "Access token obtenido en POST /custom/integrospos/login.php (campo 'access_token' de la respuesta). Se envia como header 'Authorization: Bearer <access_token>'."
)]
class OpenApiSpec
{
}
