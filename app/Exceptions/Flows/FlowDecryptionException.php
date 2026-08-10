<?php

declare(strict_types=1);

namespace App\Exceptions\Flows;

use RuntimeException;

/**
 * El payload de un Flow no se pudo descifrar.
 *
 * Se distingue de un error genérico a propósito: Meta define el código HTTP
 * 421 para "no pude descifrar, vuelve a intentar", y al recibirlo el cliente
 * de WhatsApp le pide al usuario que reintente en vez de romper el Flow.
 *
 * Un fallo de configuración local (clave privada ilegible) NO usa esta
 * excepción — eso es un 500, porque reintentar no lo va a arreglar.
 */
class FlowDecryptionException extends RuntimeException
{
}
