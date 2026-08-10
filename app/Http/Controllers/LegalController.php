<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Páginas legales públicas.
 *
 * Meta abre estas URLs automáticamente durante App Review y rechaza la
 * aplicación si devuelven 404 o exigen autenticación. Por eso las rutas viven
 * fuera de todo middleware de sesión.
 *
 * Las tres vistas comparten los mismos datos de empresa, así que se resuelven
 * una sola vez en datosEmpresa().
 */
class LegalController extends Controller
{
    /**
     * Datos de la empresa que consumen las tres vistas legales.
     *
     * @return array<string, string>
     */
    private function datosEmpresa(): array
    {
        return [
            'razonSocial'         => (string) config('empresa.razon_social'),
            'rif'                 => (string) config('empresa.rif'),
            'correoContacto'      => (string) config('empresa.correo_contacto'),
            'telefono'            => (string) config('empresa.telefono'),
            'direccion'           => (string) config('empresa.direccion'),
            'ciudadJurisdiccion'  => (string) config('empresa.ciudad_jurisdiccion'),
            'ultimaActualizacion' => (string) config('empresa.legal_actualizado'),
        ];
    }

    /**
     * GET /privacidad
     *
     * Es la URL que se carga en el campo "Privacy Policy URL" del App Dashboard.
     */
    public function privacidad(): View
    {
        return view('legal.privacidad', $this->datosEmpresa());
    }

    /**
     * GET /terminos
     *
     * Va en el campo "Terms of Service URL" del App Dashboard.
     */
    public function terminos(): View
    {
        return view('legal.terminos', $this->datosEmpresa());
    }

    /**
     * GET /eliminacion-de-datos
     *
     * Va en el campo "Data Deletion Instructions URL" del App Dashboard.
     *
     * Meta acepta dos formatos para este campo: una URL de instrucciones (esta)
     * o un callback que recibe un signed_request y responde JSON. Elegimos las
     * instrucciones porque el CRM no necesita borrado automatizado — el volumen
     * es bajo y las solicitudes se atienden a mano.
     */
    public function eliminacionDatos(): View
    {
        return view('legal.eliminacion-datos', $this->datosEmpresa());
    }
}
