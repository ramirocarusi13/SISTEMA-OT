<!DOCTYPE html>
<html>

<head>
    <title>{{ $encabezado }}</title>
</head>

<body>
    <h1>{{ $encabezado }}</h1>

    <p><strong>Título:</strong> {{ $issue->titulo }}</p>
    <p><strong>Departamento destino:</strong> {{ $issue->departamentoDestino->nombre ?? 'N/D' }}</p>
    <p><strong>Prioridad:</strong> {{ $prioridadLabel }}</p>
    <p><strong>Estado:</strong> {{ $estadoLabel }}</p>
    <p><strong>Creado por:</strong> {{ $issue->creador->name ?? 'N/D' }}</p>

    @if($texto)
        <p><strong>Comentario:</strong></p>
        <p>{!! nl2br(e($texto)) !!}</p>
    @endif

    <p><strong>Abrir el issue:</strong> <a href="{{ $url }}">{{ $url }}</a></p>

    <p>Este aviso lo envía automáticamente el Sistema OT. No respondas a este correo; comentá desde la aplicación.</p>
</body>

</html>
