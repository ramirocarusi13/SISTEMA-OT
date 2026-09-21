<?php

namespace App\Mail;

use App\Models\OpenIssue;
use App\Models\OpenIssueItem;
use App\Models\User;
use App\Support\OpenIssueEstados;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Mail único y parametrizado por evento del módulo Open Issues (calca el
 * patrón de App\Mail\HheePendienteFirmaMail / HheeEstadoCambiadoMail, pero
 * con un solo Mailable para los eventos en vez de uno por tipo). Ver
 * App\Support\OpenIssueNotificador, que arma cada instancia con los mismos
 * destinatarios que la campana correspondiente.
 *
 * $evento: 'involucrado' | 'actualizacion' | 'cambio_estado' | 'cierre' |
 * 'reapertura' | 'item_estado'.
 * $texto: comentario/motivo de cierre, cuando corresponde (null si no hay).
 * $estadoNuevo: LABEL en español del estado nuevo (ya resuelto por el
 * caller vía OpenIssueEstados::label()/ITEM_ESTADOS_LABELS), para
 * 'cambio_estado' e 'item_estado'.
 * $item (§10): item del que trata el mail, en dos casos: 'item_estado'
 * (siempre) y 'involucrado' cuando es el aviso de "te asignaron el item"
 * (App\Support\OpenIssueNotificador::notificarResponsableAsignado()) en vez
 * del aviso genérico de "te involucraron en el issue".
 * $cantidadItems (revisión #5): variante en LOTE del aviso anterior
 * (App\Support\OpenIssueNotificador::notificarResponsableAsignadoLote()),
 * cuando al mismo responsable le tocó más de un item en la misma operación.
 * Mutuamente excluyente con $item: nunca vienen los dos juntos.
 */
class OpenIssueMail extends Mailable
{
    use Queueable, SerializesModels;

    public OpenIssue $issue;
    public User $actor;
    public string $evento;
    public ?string $texto;
    public ?string $estadoNuevo;
    public ?OpenIssueItem $item;
    public ?int $cantidadItems;

    public function __construct(OpenIssue $issue, User $actor, string $evento, ?string $texto = null, ?string $estadoNuevo = null, ?OpenIssueItem $item = null, ?int $cantidadItems = null)
    {
        $this->issue = $issue;
        $this->actor = $actor;
        $this->evento = $evento;
        $this->texto = $texto;
        $this->estadoNuevo = $estadoNuevo;
        $this->item = $item;
        $this->cantidadItems = $cantidadItems;
    }

    public function build()
    {
        $this->issue->loadMissing(['departamentoDestino', 'creador']);

        $tituloCorto = Str::limit($this->issue->titulo, 60);
        $tituloItemCorto = $this->item ? Str::limit($this->item->titulo, 80) : null;

        // 'involucrado' con item: es el aviso de "te asignaron el item"
        // (App\Support\OpenIssueNotificador::notificarResponsableAsignado()),
        // no el genérico de "te involucraron en el issue" (§10.5).
        $asunto = match ($this->evento) {
            'involucrado' => $this->item
                ? "Open Issue #{$this->issue->id}: te asignaron el item \"{$tituloItemCorto}\""
                : ($this->cantidadItems
                    ? "Open Issue #{$this->issue->id}: te asignaron {$this->cantidadItems} items"
                    : "Open Issue #{$this->issue->id}: te involucraron en \"{$tituloCorto}\""),
            'actualizacion' => "Open Issue #{$this->issue->id}: nueva actualización de {$this->actor->name}",
            'cambio_estado' => "Open Issue #{$this->issue->id}: pasó a {$this->estadoNuevo}",
            'item_estado' => "Open Issue #{$this->issue->id}: item {$this->estadoNuevo} por {$this->actor->name}",
            'cierre' => "Open Issue #{$this->issue->id}: cerrado por {$this->actor->name}",
            'reapertura' => "Open Issue #{$this->issue->id}: reabierto por {$this->actor->name}",
            default => "Open Issue #{$this->issue->id}: actualización",
        };

        $encabezado = match ($this->evento) {
            'involucrado' => $this->item
                ? "{$this->actor->name} te asignó el item \"{$tituloItemCorto}\" del Open Issue #{$this->issue->id}"
                : ($this->cantidadItems
                    ? "{$this->actor->name} te asignó {$this->cantidadItems} items del Open Issue #{$this->issue->id}"
                    : "{$this->actor->name} te involucró en el Open Issue #{$this->issue->id}"),
            'actualizacion' => $this->item
                ? "{$this->actor->name} comentó en el item \"{$tituloItemCorto}\" del Open Issue #{$this->issue->id}"
                : "{$this->actor->name} agregó una actualización en el Open Issue #{$this->issue->id}",
            'cambio_estado' => "{$this->actor->name} cambió el estado del Open Issue #{$this->issue->id} a {$this->estadoNuevo}",
            'item_estado' => "{$this->actor->name} marcó como {$this->estadoNuevo} el item \"{$tituloItemCorto}\" del Open Issue #{$this->issue->id}",
            'cierre' => "{$this->actor->name} cerró el Open Issue #{$this->issue->id}",
            'reapertura' => "{$this->actor->name} reabrió el Open Issue #{$this->issue->id}",
            default => "Actualización del Open Issue #{$this->issue->id}",
        };

        $url = rtrim(config('open_issues.front_url'), '/') . '/open-issues?issue=' . $this->issue->id;

        return $this->subject($asunto)
            ->view('emails.open_issue')
            ->text('emails.open_issue_texto')
            ->replyTo(config('mail.from.address'))
            ->with([
                'issue' => $this->issue,
                'actor' => $this->actor,
                'evento' => $this->evento,
                'texto' => $this->texto,
                'estadoNuevo' => $this->estadoNuevo,
                'item' => $this->item,
                'encabezado' => $encabezado,
                'prioridadLabel' => OpenIssueEstados::PRIORIDADES_LABELS[$this->issue->prioridad]['label'] ?? $this->issue->prioridad,
                'estadoLabel' => OpenIssueEstados::label($this->issue->estado),
                'url' => $url,
            ]);
    }
}
