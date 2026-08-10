<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Exceptions\Flows\FlowDecryptionException;
use RuntimeException;
use SensitiveParameter;

/**
 * Cifrado del canal de datos de WhatsApp Flows.
 *
 * Meta usa un esquema híbrido en cada request al endpoint:
 *
 *   1. Genera una clave AES-128 aleatoria y un IV, ambos de un solo uso.
 *   2. Cifra el cuerpo real con AES-128-GCM usando esa clave.
 *   3. Cifra la clave AES con nuestra clave pública RSA (OAEP + SHA-256).
 *   4. Nos manda las tres cosas en base64.
 *
 * Para responder se reutiliza la MISMA clave AES, pero con el IV invertido
 * bit a bit (esto lo exige Meta: reusar el IV tal cual con la misma clave
 * rompería la seguridad de GCM).
 *
 * Referencia:
 * https://developers.facebook.com/docs/whatsapp/flows/guides/implementingyourflowendpoint
 */
class FlowEncryptionService
{
    /**
     * GCM produce un tag de autenticación de 16 bytes que viaja pegado al
     * final del ciphertext.
     */
    private const TAG_LENGTH = 16;

    private const CIPHER = 'aes-128-gcm';

    /**
     * Descifra el cuerpo de una request de Meta.
     *
     * @param  array<string, mixed> $payload  Cuerpo crudo de la request.
     * @return array{body: array<string, mixed>, aesKey: string, iv: string}
     *
     * @throws FlowDecryptionException Si falta algún campo o el descifrado falla.
     *         El controlador lo traduce a HTTP 421, que es lo que Meta espera
     *         para pedirnos que reintentemos.
     */
    public function decryptRequest(
        array $payload,
        #[SensitiveParameter] string $privateKeyPem,
        #[SensitiveParameter] string $passphrase = '',
    ): array {
        foreach (['encrypted_flow_data', 'encrypted_aes_key', 'initial_vector'] as $campo) {
            if (empty($payload[$campo]) || ! is_string($payload[$campo])) {
                throw new FlowDecryptionException("Falta el campo '{$campo}' en la request.");
            }
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem, $passphrase);

        if ($privateKey === false) {
            // Contraseña incorrecta o PEM corrupto. No es culpa de Meta, así que
            // se distingue del resto: esto es un 500, no un 421.
            throw new RuntimeException(
                'No se pudo cargar la clave privada. Revisa FLOWS_PRIVATE_KEY y FLOWS_PASSPHRASE.'
            );
        }

        // Paso 1: recuperar la clave AES descifrándola con nuestra privada.
        $aesKey = '';
        $ok = openssl_private_decrypt(
            base64_decode($payload['encrypted_aes_key'], true) ?: '',
            $aesKey,
            $privateKey,
            OPENSSL_PKCS1_OAEP_PADDING,
        );

        if (! $ok) {
            // Suele significar que Meta está cifrando con una pública distinta
            // a la que tenemos: hay que volver a subir la clave.
            throw new FlowDecryptionException(
                'No se pudo descifrar la clave AES. La pública en Meta no coincide con la privada local.'
            );
        }

        $datosCifrados = base64_decode($payload['encrypted_flow_data'], true);
        $iv            = base64_decode($payload['initial_vector'], true);

        if ($datosCifrados === false || $iv === false) {
            throw new FlowDecryptionException('Los campos cifrados no son base64 válido.');
        }

        if (strlen($datosCifrados) <= self::TAG_LENGTH) {
            throw new FlowDecryptionException('El payload cifrado es más corto que el tag GCM.');
        }

        // Paso 2: separar el tag GCM, que va en los últimos 16 bytes.
        $ciphertext = substr($datosCifrados, 0, -self::TAG_LENGTH);
        $tag        = substr($datosCifrados, -self::TAG_LENGTH);

        $json = openssl_decrypt($ciphertext, self::CIPHER, $aesKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($json === false) {
            throw new FlowDecryptionException('Falló el descifrado AES-GCM (tag inválido o datos alterados).');
        }

        $body = json_decode($json, true);

        if (! is_array($body)) {
            throw new FlowDecryptionException('El contenido descifrado no es JSON válido.');
        }

        return ['body' => $body, 'aesKey' => $aesKey, 'iv' => $iv];
    }

    /**
     * Cifra la respuesta reutilizando la clave AES de la request.
     *
     * Meta espera un string base64 plano como cuerpo de la respuesta HTTP
     * (no un JSON con el base64 dentro).
     *
     * @param array<string, mixed> $respuesta
     */
    public function encryptResponse(
        array $respuesta,
        #[SensitiveParameter] string $aesKey,
        string $iv,
    ): string {
        $tag = '';

        $cifrado = openssl_encrypt(
            json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            self::CIPHER,
            $aesKey,
            OPENSSL_RAW_DATA,
            $this->flipIv($iv),
            $tag,
        );

        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar la respuesta del Flow.');
        }

        // El tag se concatena al final, igual que hace Meta al enviarnos datos.
        return base64_encode($cifrado . $tag);
    }

    /**
     * Invierte todos los bits del IV.
     *
     * Requisito de Meta: la respuesta se cifra con el mismo AES key pero con
     * el IV complementado. Sin esto el cliente de WhatsApp no puede descifrar
     * y el Flow se queda cargando sin mostrar error.
     */
    private function flipIv(string $iv): string
    {
        return implode('', array_map(
            static fn (string $byte): string => chr(~ord($byte) & 0xFF),
            str_split($iv),
        ));
    }

    /**
     * Valida la firma HMAC que Meta pone en X-Hub-Signature-256.
     *
     * Mismo mecanismo que ya usan los webhooks de WhatsApp/Instagram, pero
     * aquí un fallo se responde con 432 en vez de 403 — es el código que la
     * documentación de Flows define para firma inválida.
     */
    public function isSignatureValid(string $rawBody, string $signature, #[SensitiveParameter] ?string $appSecret = null): bool
    {
        $appSecret ??= (string) config('services.meta.app_secret');

        if ($appSecret === '' || $signature === '') {
            return false;
        }

        $esperada = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        // hash_equals evita filtrar información por tiempo de comparación.
        return hash_equals($esperada, $signature);
    }
}
