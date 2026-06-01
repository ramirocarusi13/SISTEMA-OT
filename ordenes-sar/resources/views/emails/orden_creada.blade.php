<!DOCTYPE html>
<html>

<head>
    <title>Nueva Orden de Trabajo Creada</title>
</head>

<body>
    <h1>Orden de Trabajo Creada</h1>
    <p>Estimado(a),</p>
    <p>Se ha creado una nueva orden de trabajo con la siguiente información:</p>

    <ul>
        <li><strong>ID de Orden:</strong> {{ $ordenTrabajo->id }}</li>
        <li><strong>Creador:</strong> {{ $usuarioCreador->name }}</li>
        <li><strong>Departamento:</strong> {{ $usuarioCreador->departamento->nombre ?? 'N/A' }}</li>
        <li><strong>Titulo:</strong> {{ $ordenTrabajo->titulo }}</li>
    </ul>

    <p>Por favor, revise la orden de trabajo en el sistema para más detalles.</p>

    <img src="{{ asset('storage/images/LOGO.png') }}" alt="Firma" style="max-width: 200px; height: auto;">



    <p><strong>BOT SISTEMAS</strong></p>


</body>

</html>
