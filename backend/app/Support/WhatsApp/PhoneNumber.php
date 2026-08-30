<?php

namespace App\Support\WhatsApp;

/**
 * Normaliza um telefone brasileiro guardado em `ex_users.whatsapp` (formato
 * de entrada `(XX) 9XXXX-XXXX`, validado em `UserController@updateProfile`)
 * para o formato que a Meta WhatsApp Cloud API espera no campo `to`: só
 * dígitos, com o DDI `55` na frente (`5511912345678`).
 *
 * Retorna `null` quando a entrada não tem cara de telefone BR válido — o
 * chamador trata isso como "não dá para notificar este usuário".
 */
class PhoneNumber
{
    public static function toApiFormat(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        // Já veio com o DDI 55 (ex.: "+55 11 91234-5678" ou "5511912345678").
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            $national = substr($digits, 2);
        } else {
            $national = $digits;
        }

        // DDD (2) + assinante: 8 dígitos (fixo) ou 9 (móvel).
        if (strlen($national) !== 10 && strlen($national) !== 11) {
            return null;
        }

        return '55'.$national;
    }
}
