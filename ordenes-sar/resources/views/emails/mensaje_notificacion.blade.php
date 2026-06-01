<h1>Nuevo mensaje en la Orden de Trabajo</h1>
<p><strong>De:</strong> {{ $usuario->name }}</p>
<p><strong>ID de la Orden:</strong> {{ $ordenTrabajo->id }}</p>
<p><strong>Título:</strong> {{ $ordenTrabajo->titulo }}</p>
<p><strong>Mensaje:</strong> {{ $mensaje->mensaje }}</p>
<p>Fecha del mensaje: {{ now()->format('d-m-Y H:i:s') }}</p>


<img src="{{ asset('storage/images/LOGO.png') }}" alt="Firma" style="max-width: 200px; height: auto;">



<p><strong>BOT SISTEMAS</strong></p>
