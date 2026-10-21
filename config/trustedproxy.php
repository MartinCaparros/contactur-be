<?php

/*
 * Proxies de confianza (middleware TrustProxies del framework, que lee esta clave).
 *
 * El navegador nunca habla con Laravel: el BFF de Next reenvía cada request desde
 * su contenedor. Sin IP del cliente, `$request->ip()` es la del BFF y los límites
 * por IP (login, recuperación, contacto público) se comparten entre visitantes.
 *
 * El BFF manda `X-Forwarded-For` con un único valor SOLO si tiene configurado
 * `BFF_CLIENT_IP_HEADER`: el header que el proxy de borde (balanceador, nginx
 * delante de Next) sobrescribe con la IP real. Nunca reenvía el X-Forwarded-For
 * que manda el navegador, porque sería falsificable (hallazgo E2E de T19).
 * Laravel solo acepta el valor si REMOTE_ADDR (el BFF) está en esta lista.
 *
 * - Local (compose): vacío. El BFF no reenvía IP y Laravel ve la del contenedor de
 *   Next. Confiar en la red de Docker (172.16.0.0/12) era explotable: nginx:8080 está
 *   publicado en el host y el tráfico del host llega desde el gateway de esa red, así
 *   que cualquiera elegía su IP con X-Forwarded-For (T22, reproducido).
 * - Producción: SOLO las direcciones del BFF. No exponer el backend directamente:
 *   quien llegue desde una IP de confianza puede elegir su IP.
 * - Vacío: no se confía en nadie y se usa REMOTE_ADDR tal cual.
 */
$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' ? null : $proxies,
];
