<?php

namespace Tests\Unit;

use App\Support\WhatsApp;
use Tests\TestCase;

/**
 * Tests de App\Support\WhatsApp (normalización de celular -> chatId de
 * OpenWA y prefijo del mensaje). Extiende Tests\TestCase (no PHPUnit puro)
 * porque chatIdDesdeCelular() lee config('whatsapp.pais'), lo que requiere
 * la app de Laravel booteada. Sin RefreshDatabase: no toca la DB.
 */
class WhatsAppTest extends TestCase
{
    // =========================================================================
    // chatIdDesdeCelular(): casos válidos (país 54, default de config/whatsapp.php)
    // =========================================================================

    public static function celularesValidosProvider(): array
    {
        return [
            'celular con espacios y guion, sin codigo de pais' => ['11 5555-1234', '5491155551234@c.us'],
            'ya viene con codigo de pais y el 9 de celular' => ['5491155551234', '5491155551234@c.us'],
            'codigo de pais sin el 9 (cargado como fijo)' => ['541155551234', '5491155551234@c.us'],
            'con 0 inicial de discado nacional, sin codigo de pais' => ['01155551234', '5491155551234@c.us'],
        ];
    }

    /** @dataProvider celularesValidosProvider */
    public function test_chat_id_desde_celular_normaliza_formatos_validos(string $celular, string $chatIdEsperado): void
    {
        $this->assertSame($chatIdEsperado, WhatsApp::chatIdDesdeCelular($celular));
    }

    // =========================================================================
    // chatIdDesdeCelular(): casos que no se pueden resolver -> null
    // =========================================================================

    public static function celularesInvalidosProvider(): array
    {
        return [
            'null' => [null],
            'vacio' => [''],
            'solo simbolos, sin ningun digito' => ['---'],
            'prefijo local 15 sin codigo de area' => ['15 5555-1234'],
            'con 0 inicial y prefijo 15 sin area' => ['0 15 5555-1234'],
        ];
    }

    /** @dataProvider celularesInvalidosProvider */
    public function test_chat_id_desde_celular_devuelve_null_para_casos_no_resolubles(?string $celular): void
    {
        $this->assertNull(WhatsApp::chatIdDesdeCelular($celular));
    }

    // =========================================================================
    // textoConPrefijo()
    // =========================================================================

    public function test_texto_con_prefijo_antepone_el_prefijo_configurado(): void
    {
        $this->assertSame('[Sistema OT] Hola', WhatsApp::textoConPrefijo('Hola'));
    }
}
