<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Datos legales de la empresa
|
| Los consumen las vistas públicas de resources/views/legal/ (política de
| privacidad, términos y eliminación de datos), que Meta revisa durante App
| Review.
|
| Están aquí y no hardcodeados en las vistas para que cambiar la razón social o
| el correo de contacto sea editar el .env del VPS, sin tocar Blade ni
| redesplegar vistas.
|
| PENDIENTE: los valores por defecto son placeholders. Cuando el cliente
| entregue los datos reales, cargarlos en el .env de producción y correr
| `php8.4 artisan config:cache`.
|------------------------------------------------------------------------------
*/

return [

    // Razón social completa, tal como aparece en el registro mercantil.
    // OJO: debe coincidir EXACTAMENTE con el nombre del portafolio de negocio
    // en Meta Business Suite, o la verificación del negocio es rechazada.
    'razon_social' => env('EMPRESA_RAZON_SOCIAL', 'Reino Aromas'),

    // RIF. Si queda vacío, las vistas omiten la mención en vez de mostrar un
    // hueco o un placeholder feo.
    'rif' => env('EMPRESA_RIF', ''),

    // Correo al que llegan las solicitudes de datos. Aparece en las tres
    // páginas legales y debe ser una bandeja atendida: los plazos de respuesta
    // publicados son 72h para acuse y 30 días para completar.
    'correo_contacto' => env('EMPRESA_CORREO', 'reinoaromas3@gmail.com'),

    'telefono' => env('EMPRESA_TELEFONO', 'Por definir'),

    'direccion' => env('EMPRESA_DIRECCION', 'Caracas, Venezuela'),

    // Ciudad cuyos tribunales se nombran en la cláusula de jurisdicción de los
    // términos y condiciones.
    'ciudad_jurisdiccion' => env('EMPRESA_CIUDAD_JURISDICCION', 'Caracas'),

    /*
    |--------------------------------------------------------------------------
    | Fecha de última actualización de los documentos legales
    |
    | Se muestra bajo el título de cada página. Es un valor fijo y no la fecha
    | de hoy a propósito: debe reflejar cuándo cambió el texto por última vez,
    | no cuándo se cargó la página. Si dijera "hoy" siempre, un usuario no
    | podría saber si el documento cambió desde que lo leyó.
    |
    | Actualizar a mano al modificar cualquiera de las tres vistas.
    |--------------------------------------------------------------------------
    */
    'legal_actualizado' => env('EMPRESA_LEGAL_ACTUALIZADO', '10 de agosto de 2026'),

];
