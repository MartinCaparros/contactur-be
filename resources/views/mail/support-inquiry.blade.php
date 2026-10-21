<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nueva consulta de soporte</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <div style="margin:0 0 20px;">@include('mail.partials.brand-logo')</div>
        <h1 style="font-size:20px;margin:0 0 16px;">Nueva consulta de soporte</h1>
        <p style="font-size:15px;margin:0 0 8px;"><strong>Nombre:</strong> {{ $inquirerName }}</p>
        <p style="font-size:15px;margin:0 0 8px;"><strong>Email:</strong> {{ $inquirerEmail }}</p>
        <p style="font-size:15px;margin:0 0 8px;"><strong>Categoría:</strong> {{ $inquiryCategory ?? 'Sin categoría' }}</p>
        <p style="font-size:15px;margin:16px 0 8px;"><strong>Mensaje:</strong></p>
        <p style="font-size:15px;line-height:1.5;margin:0 0 16px;">{!! nl2br(e($inquiryMessage)) !!}</p>
        <p style="font-size:13px;color:#57534e;margin:16px 0 0;">Referencia: {{ $inquiryId }}. Respondé este correo para contestarle directamente.</p>
    </div>
</body>
</html>
