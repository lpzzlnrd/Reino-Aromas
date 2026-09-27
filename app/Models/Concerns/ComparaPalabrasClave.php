<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Filtro por palabras clave, compartido por las dos automatizaciones de
 * Instagram (el DM por comentario y la respuesta automática al buzón).
 *
 * Vive en un trait y no duplicado porque las dos tienen que tratar igual el
 * mismo texto: si una entiende "precío" con acento y la otra no, el negocio lo
 * lee como que la automatización está rota a ratos.
 *
 * Espera que el modelo tenga un atributo `keywords` casteado a array.
 */
trait ComparaPalabrasClave
{
    /**
     * ¿El texto dispara la automatización?
     *
     * Sin palabras clave configuradas responde a todos. La comparación es
     * insensible a mayúsculas y a acentos porque la gente escribe "precio",
     * "PRECIO" y "Precío" indistintamente.
     */
    public function coincide(?string $texto): bool
    {
        $claves = $this->keywords ?? [];

        if ($claves === []) {
            return true;
        }

        if ($texto === null || trim($texto) === '') {
            return false;
        }

        $normalizado = $this->normalizar($texto);

        foreach ($claves as $clave) {
            if (! is_string($clave) || trim($clave) === '') {
                continue;
            }

            if (str_contains($normalizado, $this->normalizar($clave))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas y sin acentos, para comparar.
     *
     * Se usa una tabla explícita en vez de iconv//TRANSLIT: en Windows y según
     * la locale, iconv devuelve "?" o falla, y esto corre igual en el Laragon
     * del dev que en el VPS.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
