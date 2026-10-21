{{-- Texto plano: sin escape HTML (no se interpreta como HTML y & debe llegar literal en los links). --}}
{!! $heading !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@if ($actionUrl !== null && $actionLabel !== null)
{!! $actionLabel !!}: {!! $actionUrl !!}

@endif
@if ($footer !== null)
{!! $footer !!}
@endif
