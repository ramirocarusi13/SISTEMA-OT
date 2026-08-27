<!DOCTYPE html>
<html>

<head>
    <title>Solicitud HHEE actualizada</title>
</head>

<body>
    <h1>Hola, {{ $solicitud->solicitante->name ?? '' }}</h1>
    <p>Te informamos que tu solicitud de HHEE <strong>#{{ $solicitud->id }}</strong> cambió de estado.</p>
    <p><strong>Nuevo estado:</strong> {{ $estadoNuevo }}</p>

    @if ($motivoRechazo)
        <p><strong>Motivo del rechazo:</strong> {{ $motivoRechazo }}</p>
    @endif

    <p>El cambio fue realizado por: {{ $actor->name }} ({{ $actor->email }})</p>

    @if ($esContingencia)
        <p><em>Esta firma se realizó por contingencia.</em></p>
    @endif

    <p>Fecha del cambio: {{ now()->format('d-m-Y H:i:s') }}</p>

    <img src="{{ asset('storage/images/LOGO.png') }}" alt="Firma" style="max-width: 200px; height: auto;">

    <p><strong>BOT SISTEMAS</strong></p>
</body>

</html>
