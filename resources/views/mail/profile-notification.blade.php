<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <div style="margin:0 0 20px;">@include('mail.partials.brand-logo')</div>
        <h1 style="font-size:20px;margin:0 0 16px;">{{ $heading }}</h1>
        @foreach ($lines as $line)
            <p style="font-size:15px;line-height:1.5;margin:0 0 12px;">{!! nl2br(e($line)) !!}</p>
        @endforeach
        @if ($actionUrl !== null && $actionLabel !== null)
            <p style="margin:24px 0;">
                <a href="{{ $actionUrl }}" style="display:inline-block;background:#1c1917;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-size:15px;">{{ $actionLabel }}</a>
            </p>
        @endif
        @if ($footer !== null)
            <p style="font-size:13px;line-height:1.5;color:#57534e;margin:16px 0 0;">{{ $footer }}</p>
        @endif
    </div>
</body>
</html>
