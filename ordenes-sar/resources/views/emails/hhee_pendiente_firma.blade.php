<!DOCTYPE html>
<html>

<head>
    <title>HHEE pendiente de firma</title>
</head>

<body>
    <h1>Tenés una solicitud de HHEE pendiente de tu firma</h1>
    <p>La solicitud <strong>HHEE #{{ $solicitud->id }}</strong> está pendiente de tu firma ({{ $etiquetaNivel }}).</p>
    <p><strong>Solicitante:</strong> {{ $solicitud->solicitante->name ?? 'N/D' }}</p>
    <p><strong>Departamento:</strong> {{ $solicitud->departamento->nombre ?? 'N/D' }}</p>
    <p><strong>Fecha HHEE:</strong> {{ optional($solicitud->fecha_hhee)->format('d-m-Y') }}</p>
    <p><strong>Turno:</strong> {{ $solicitud->turno ?? '-' }}</p>
    <p><strong>Empleados:</strong> {{ $solicitud->total_empleados }}</p>
    <p><strong>Horas teóricas totales:</strong> {{ $solicitud->total_horas_teoricas }}</p>
    <p>Enviada por: {{ $actor->name }} ({{ $actor->email }})</p>
    <p>Fecha: {{ now()->format('d-m-Y H:i:s') }}</p>

    <img src="{{ asset('storage/images/LOGO.png') }}" alt="Firma" style="max-width: 200px; height: auto;">

    <p><strong>BOT SISTEMAS</strong></p>
</body>

</html>
