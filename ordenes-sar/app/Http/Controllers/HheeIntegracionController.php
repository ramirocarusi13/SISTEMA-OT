<?php

namespace App\Http\Controllers;

use App\Models\SolicitudHhee;
use App\Models\User;
use App\Support\HheeAutorizacionException;
use App\Support\HheeFlujo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints de integración SERVIDOR-A-SERVIDOR para APP-RRHH (Laravel aparte,
 * :8587): gestiona la firma final (aprobar/rechazar) de solicitudes HHEE sin
 * pasar por el login normal de este sistema. Autenticación por header
 * X-Integracion-Key (ver App\Http\Middleware\VerificarIntegracionHhee,
 * alias 'hhee.integracion'), NUNCA auth:api/Passport -- APP-RRHH no tiene
 * (ni debe tener) un usuario/token de este sistema, solo el secreto
 * compartido.
 *
 * Delega TODO en App\Support\HheeFlujo::aprobar()/rechazar(), exactamente
 * igual que SolicitudHheeController::aprobar()/rechazar(): misma validación
 * de rol (App\Support\HheeAprobadores), mismo lock/re-chequeo de
 * concurrencia, mismas notificaciones e historial. CERO lógica de negocio
 * duplicada acá: este controller solo resuelve el User "firmante" por email
 * (APP-RRHH no conoce los ids internos de este sistema) y traduce
 * excepciones al mismo formato HTTP que el resto del módulo.
 *
 * Contrato de respuesta: exactamente el mismo shape que
 * SolicitudHheeController::aprobar()/rechazar() (la solicitud actualizada),
 * para que APP-RRHH la muestre sin transformar nada.
 */
class HheeIntegracionController extends Controller
{
    /**
     * Sufijo de auditoría: se agrega SIEMPRE al comentario/motivo de la firma
     * (columnas hhee_aprobaciones.comentario / hhee_historial.comentario,
     * ambas string(1000)), para poder distinguir en el historial una firma
     * hecha vía APP-RRHH de una hecha directamente en este sistema.
     */
    private const SUFIJO_INTEGRACION = '(vía APP-RRHH)';

    public function aprobar(Request $request, $id)
    {
        $validado = $request->validate([
            'email_firmante' => 'required|email',
            'comentario' => 'nullable|string|max:1000',
        ]);

        $firmante = User::where('email', $validado['email_firmante'])->first();

        if (!$firmante) {
            return response()->json([
                'error' => 'El firmante no existe como usuario del Sistema OT',
            ], 404);
        }

        try {
            $solicitud = SolicitudHhee::findOrFail($id);

            $comentario = $this->conSufijoIntegracion($validado['comentario'] ?? null);
            $solicitud = HheeFlujo::aprobar($solicitud, $firmante, $comentario);

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error (integración APP-RRHH) aprobando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error aprobando la solicitud'], 500);
        }
    }

    public function rechazar(Request $request, $id)
    {
        $validado = $request->validate([
            'email_firmante' => 'required|email',
            'motivo' => 'required|string|max:1000',
        ]);

        $firmante = User::where('email', $validado['email_firmante'])->first();

        if (!$firmante) {
            return response()->json([
                'error' => 'El firmante no existe como usuario del Sistema OT',
            ], 404);
        }

        try {
            $solicitud = SolicitudHhee::findOrFail($id);

            $motivo = $this->conSufijoIntegracion($validado['motivo']);
            $solicitud = HheeFlujo::rechazar($solicitud, $firmante, $motivo);

            return response()->json($solicitud);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        } catch (HheeAutorizacionException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error (integración APP-RRHH) rechazando la solicitud HHEE: ' . $e->getMessage());
            return response()->json(['error' => 'Error rechazando la solicitud'], 500);
        }
    }

    /**
     * Agrega el sufijo de auditoría sin superar el límite de 1000
     * caracteres de las columnas comentario (recorta la BASE, no el
     * sufijo, si hiciera falta). Si no viene texto (comentario opcional al
     * aprobar), el sufijo queda solo, sirviendo igual de marca de auditoría.
     */
    private function conSufijoIntegracion(?string $texto): string
    {
        if (empty($texto)) {
            return self::SUFIJO_INTEGRACION;
        }

        $maxLargo = 1000;
        $disponibleParaBase = $maxLargo - (mb_strlen(self::SUFIJO_INTEGRACION) + 1);

        $base = mb_strlen($texto) > $disponibleParaBase
            ? mb_substr($texto, 0, $disponibleParaBase)
            : $texto;

        return $base . ' ' . self::SUFIJO_INTEGRACION;
    }
}
