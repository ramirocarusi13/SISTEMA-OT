{{ $encabezado }}

Título: {{ $issue->titulo }}
Departamento destino: {{ $issue->departamentoDestino->nombre ?? 'N/D' }}
Prioridad: {{ $prioridadLabel }}
Estado: {{ $estadoLabel }}
Creado por: {{ $issue->creador->name ?? 'N/D' }}
@if($texto)

Comentario:
{{ $texto }}
@endif

Abrir el issue: {{ $url }}

Este aviso lo envía automáticamente el Sistema OT. No respondas a este correo; comentá desde la aplicación.
