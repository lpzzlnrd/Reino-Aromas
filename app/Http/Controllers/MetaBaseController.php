<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class MetaBaseController extends Controller
{
    protected function jsonError(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function jsonSuccess(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['status' => true], $data), $status);
    }

    /**
     * Construye un ORDER BY que respeta el orden de $valores.
     *
     * Equivalente portable de FIELD(columna, ...) de MySQL, que es exclusivo de
     * ese motor: los tests corren sobre SQLite, donde FIELD no existe y la
     * consulta revienta. CASE es SQL estándar y funciona en los dos.
     *
     * Vive en la base porque lo necesitan varios listados (tickets por etapa
     * del embudo, usuarios por rol) y cada uno tenía su propia copia.
     *
     * Los valores se escapan aunque hoy vengan solo de constantes de los
     * modelos: si algún día se alimenta desde el request, no debe convertirse
     * en una inyección SQL.
     *
     * @param list<string> $valores
     */
    protected function ordenPor(string $columna, array $valores): string
    {
        $casos = '';
        foreach (array_values($valores) as $i => $valor) {
            $escapado = str_replace("'", "''", $valor);
            $casos .= " WHEN '{$escapado}' THEN {$i}";
        }

        // El else deja al final cualquier valor inesperado de la columna.
        return "CASE {$columna}{$casos} ELSE " . count($valores) . ' END';
    }
}
