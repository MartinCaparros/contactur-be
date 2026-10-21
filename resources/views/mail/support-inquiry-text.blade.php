{{-- Texto plano: sin escape HTML (no se interpreta como HTML). --}}
Nueva consulta de soporte

Nombre: {!! $inquirerName !!}
Email: {!! $inquirerEmail !!}
Categoría: {!! $inquiryCategory ?? 'Sin categoría' !!}

Mensaje:
{!! $inquiryMessage !!}

Referencia: {!! $inquiryId !!}. Respondé este correo para contestarle directamente.
