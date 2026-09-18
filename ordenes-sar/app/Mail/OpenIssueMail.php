<?php

namespace App\Mail;

use App\Models\OpenIssue;
use App\Models\User;
use App\Support\OpenIssueEstados;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Mail único y parametrizado por evento del módulo Open Issues (calca el
 * patrón de App\Mail\HheePendienteFirmaMail / HheeEstadoCambiadoMail, pero
 * con un solo Mailable para los 5 eventos en vez de uno por tipo). Ver
 * App\Support\OpenIssueNotificador, que arma cada instancia con los mismos
 * destinatarios que la campana correspondiente.
 *
 * $evento: 'involucrado' | 'actualizacion' | 'cambio_estado' | 'cierre' | 'reapertura'.
 * $texto: comentario/motivo de cierre, cuando corresponde (null si no hay).
 * $estadoNuevo: LABEL en español del estado nuevo (ya resuelto por el
 * caller vía OpenIssueEstados::label()), solo para 'cambio_estado'.
 */
class OpenIssueMail extends Mailable
{
    use Queueable, SerializesModels;

    public OpenIssue $issue;
    public User $actor;
    public string $evento;
    public ?string $texto;
    public ?string $estadoNuevo;

    public function __construct(OpenIssue $issue, User $actor, string $evento, ?string $texto = null, ?string $estadoNuevo = null)
    {
        $this->issue = $issue;
        $this->actor = $actor;
        $this->evento = $evento;
        $this->texto = $texto;
        $this->estadoNuevo = $estadoNuevo;
    }

    public function build()
    {
        $this->issue->loadMissing(['departamentoDestino', 'creador']);

        $tituloCorto = Str::limit($this->issue->titulo, 60);

        $asunto = match ($this->evento) {
            'involucrado' => "Open Issue #{$this->issue->id}: te involucraron en \"{$tituloCorto}\"",
            'actualizacion' => "Open Issue #{$this->issue->id}: nueva actualización de {$this->actor->name}",
            'cambio_estado' => "Open Issue #{$this->issue->id}: pasó a {$this->estadoNuevo}",
            'cierre' => "Open Issue #{$this->issue->id}: cerrado por {$this->actor->name}",
            'reapertura' => "Open Issue #{$this->issue->id}: reabierto por {$this->actor->name}",
            default => "Open Issue #{$this->issue->id}: actualización",
        };

        $encabezado = match ($this->evento) {
            'involucrado' => "{$this->actor->name} te involucró en el Open Issue #{$this->issue->id}",
            'actualizacion' => "{$this->actor->name} agregó una actualización en el Open Issue #{$this->issue->id}",
            'cambio_estado' => "{$this->actor->name} cambió el estado del Open Issue #{$this->issue->id} a {$this->estadoNuevo}",
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
                'encabezado' => $encabezado,
                'prioridadLabel' => OpenIssueEstados::PRIORIDADES_LABELS[$this->issue->prioridad]['label'] ?? $this->issue->prioridad,
                'estadoLabel' => OpenIssueEstados::label($this->issue->estado),
                'url' => $url,
            ]);
    }
}
