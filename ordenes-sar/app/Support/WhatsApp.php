<?php

namespace App\Support;

/**
 * Helpers PUROS (sin IO) del canal WhatsApp: normalización de celulares a
 * chatId de OpenWA y armado del texto del mensaje. Separado de
 * App\Support\WhatsAppNotificador (que sí hace IO: consulta la DB y despacha
 * el job) para poder testear la normalización sin tocar nada más.
 */
class WhatsApp
{
    /**
     * Convierte un celular cargado a mano (con espacios, guiones, 0 inicial,
     * con o sin código de país, etc.) al chatId que espera OpenWA
     * ("{digitos}@c.us"). Reglas (celular argentino, ver config('whatsapp.pais')):
     *
     *  1. Se descartan todos los caracteres que no sean dígitos. Si no queda
     *     ningún dígito, no hay celular que normalizar -> null.
     *  2. Si el número empieza con '0' (discado nacional: "011 15..."), se
     *     quita ese 0.
     *  3. Si lo que queda empieza con '15' (prefijo local de celular
     *     argentino SIN código de área, ej. un celular cargado a mano como
     *     "15 5555-1234"), no hay forma de reconstruir el área/código de
     *     país sin ambigüedad -> null (el caller loguea el caso).
     *  4. Si el número NO empieza con el código de país configurado, se
     *     antepone país + '9' (así arma un celular argentino completo:
     *     54 9 <área><número>).
     *  5. Si el número YA empieza con el código de país pero el dígito
     *     siguiente no es '9' (número cargado como fijo, sin el 9 de
     *     celular), se inserta el '9' justo después del país.
     *     Si ya tiene el '9', se deja tal cual.
     *
     * Ejemplos (pais=54): "11 5555-1234" -> "5491155551234@c.us";
     * "5491155551234" -> "5491155551234@c.us" (ya estaba bien formado);
     * "541155551234" -> "5491155551234@c.us" (fijo->celular, falta el 9).
     */
    public static function chatIdDesdeCelular(?string $celular): ?string
    {
        if ($celular === null) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $celular);

        if ($digitos === '' || $digitos === null) {
            return null;
        }

        if (str_starts_with($digitos, '0')) {
            $digitos = substr($digitos, 1);
        }

        if ($digitos === '') {
            return null;
        }

        if (str_starts_with($digitos, '15')) {
            return null;
        }

        $pais = (string) config('whatsapp.pais', '54');

        if (!str_starts_with($digitos, $pais)) {
            $digitos = $pais . '9' . $digitos;
        } else {
            $resto = substr($digitos, strlen($pais));

            if (!str_starts_with($resto, '9')) {
                $digitos = $pais . '9' . $resto;
            }
        }

        return "{$digitos}@c.us";
    }

    /**
     * Antepone config('whatsapp.prefijo') al texto, para que quede claro de
     * qué sistema viene el mensaje (los destinatarios pueden recibir
     * WhatsApp de varias apps internas).
     */
    public static function textoConPrefijo(string $texto): string
    {
        $prefijo = trim((string) config('whatsapp.prefijo', ''));

        return $prefijo === '' ? $texto : "{$prefijo} {$texto}";
    }
}
