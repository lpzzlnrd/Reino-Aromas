<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de etiquetas.
 *
 * Existe porque faltaba la mitad del par: PUT /api/tickets/{id}/tags ya
 * permitía asignar etiquetas a un ticket, pero recibe `tag_ids` y no había
 * ningún endpoint que dijera qué etiquetas existen. El panel del chat podía
 * mostrar las del ticket y no ofrecer ninguna nueva.
 *
 * Solo lectura: las etiquetas las define el negocio y se siembran con
 * TagsSeeder. Un CRUD desde la app llenaría la tabla de variantes escritas a
 * mano ("VIP", "vip", "V.I.P.") que romperían los reportes por etiqueta.
 */
class TagController extends MetaBaseController
{
    /**
     * GET /api/tags
     *
     * El catálogo completo, con el conteo de tickets de cada etiqueta.
     *
     * Sin paginar a propósito: son las etiquetas del negocio, una docena larga
     * como máximo, y el selector del chat las necesita todas de una vez.
     */
    public function index(): JsonResponse
    {
        $tags = Tag::query()
            ->withCount('tickets')
            ->orderBy('name')
            ->get();

        return response()->json(
            $tags->map(fn (Tag $tag): array => [
                'id'      => $tag->id,
                'name'    => $tag->name,
                'color'   => $tag->color,
                // Cuántos tickets la usan. El selector muestra primero las más
                // usadas y esto le da con qué ordenar.
                'tickets' => (int) $tag->getAttribute('tickets_count'),
            ])
        );
    }
}
