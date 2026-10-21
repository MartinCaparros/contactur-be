{{-- T27: reemplaza el header del tema por defecto de Laravel (solo HTML; el texto plano sigue igual). Muestra el logo oficial y enlaza al sitio público en vez de a la URL de la API. Sin sangría: el tema arma estos componentes junto con Markdown. --}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ config('contactur.frontend_url') }}" style="display: inline-block;">
@include('mail.partials.brand-logo')
</a>
</td>
</tr>
