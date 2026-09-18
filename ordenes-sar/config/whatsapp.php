<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canal WhatsApp (gateway OpenWA self-hosted)
    |--------------------------------------------------------------------------
    |
    | Notificaciones (OT, HHEE, Open Issues) reenviadas por WhatsApp a los
    | usuarios que tengan 'celular' cargado y 'whatsapp_activo' = true (ver
    | App\Support\WhatsAppNotificador, enganchado en Notificacion::booted()).
    |
    | Contrato del gateway (repo rmyndharis/OpenWA):
    |   POST {url}/api/sessions/{session}/messages/send-text
    |   Headers: Content-Type: application/json, X-API-Key: {api_key}
    |   Body: {"chatId": "5491155551234@c.us", "text": "..."}
    |
    | Apagado por defecto (enabled=false): hay que prenderlo a propósito por
    | entorno una vez que el gateway esté levantado y probado.
    |
    */

    'enabled' => (bool) env('WHATSAPP_ENABLED', false),

    // URL base del gateway OpenWA (sin barra final). Puerto por defecto 2785.
    'url' => env('WHATSAPP_URL', 'http://localhost:2785'),

    // Nombre de la sesión de WhatsApp Web ya vinculada en el gateway.
    'session' => env('WHATSAPP_SESSION', 'sistema-ot'),

    // API key del gateway (header X-API-Key). NUNCA loguear este valor.
    'api_key' => env('WHATSAPP_API_KEY'),

    // Timeout (segundos) del POST a OpenWA.
    'timeout' => 10,

    // Código de país usado para normalizar celulares locales sin código de
    // país (ver App\Support\WhatsApp::chatIdDesdeCelular()). Argentina = 54.
    'pais' => env('WHATSAPP_PAIS', '54'),

    // Texto que antecede cada mensaje enviado por este canal, para que quede
    // claro de qué sistema viene (ver App\Support\WhatsApp::textoConPrefijo()).
    'prefijo' => env('WHATSAPP_PREFIJO', '[Sistema OT]'),

];
