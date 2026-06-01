<!DOCTYPE html>
<html>

<head>
    <title>Cambio de Estado</title>
</head>

<body>
    <h1>Hola, {{ $ordenTrabajo->creador->name }}</h1>
    <p>Te informamos que la Orden de Trabajo <strong>#{{ $ordenTrabajo->id }}</strong> ha cambiado de estado.</p>
    <p><strong>Estado anterior:</strong> {{ $estadoAnterior }}</p>
    <p><strong>Nuevo estado:</strong> {{ $estadoNuevo }}</p>
    <p>El cambio fue realizado por: {{ $usuario->name }} ({{ $usuario->email }})</p>
    <p>Fecha del cambio: {{ now()->format('d-m-Y H:i:s') }}</p>

    <img src="{{ asset('storage/images/LOGO.png') }}" alt="Firma" style="max-width: 200px; height: auto;">



    <p><strong>BOT SISTEMAS</strong></p>




</body>

</html>
