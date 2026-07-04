# Política de seguridad

[Català](SECURITY.ca.md) · [English](SECURITY.md) · [Castellano](SECURITY.es.md)

MetaWhatsApp toca, por definición, las piezas más sensibles de una instalación de FreeScout: credenciales de la API de Meta, un webhook expuesto públicamente, colas de trabajo y una integración externa que mueve conversaciones privadas de clientes. Por eso los reportes responsables de seguridad son especialmente importantes para este proyecto, y nos los tomamos en serio.

## Versiones soportadas

| Versión | Soporte de seguridad |
|---|---|
| 1.x (rama principal) | Sí |
| Versiones anteriores | No |

Este módulo está pensado para FreeScout 1.8.x. Las vulnerabilidades en versiones de FreeScout no soportadas por el proyecto upstream quedan fuera del alcance de este proyecto.

## Cómo reportar una vulnerabilidad

No abras issues ni pull requests públicas para vulnerabilidades de seguridad. Una publicación antes de tener la corrección pone en riesgo todas las instalaciones que usan el módulo.

Canales privados, por orden de preferencia:

1. GitHub → Security → Report a vulnerability, en este repositorio.
2. Correo electrónico: losimo@gmail.com

## Qué debe incluir el reporte

Intenta incluir, como mínimo:

- Versión del módulo, versión de FreeScout y versión de PHP.
- Descripción clara de la vulnerabilidad y de su impacto.
- Pasos de reproducción o prueba de concepto, si la tienes.
- Cualquier configuración relevante para reproducirla.

No envíes secretos, tokens, contraseñas ni datos privados que no sean estrictamente necesarios.

## Compromiso de respuesta

Este proyecto se mantiene en tiempo personal. No prometemos un SLA formal, pero nos comprometemos a:

- Confirmar la recepción, normalmente dentro de una semana.
- Hacer una primera evaluación y proponer un plan de acción, normalmente dentro de 30 días.
- Coordinar la corrección y la divulgación con la persona que reporta antes de cualquier publicación pública.

## Qué consideramos vulnerabilidad

Consideramos incidencia de seguridad, entre otras:

- Cualquier bypass de la verificación HMAC del webhook.
- Exposición de credenciales, tokens o secretos en logs, respuestas HTTP o excepciones.
- Inyección o manipulación vía payload del webhook.
- Misatribución o inyección cruzada entre cuentas o canales.
- Bypass de autorización en las pantallas de administración.
- CSRF en acciones sensibles de administración.

## Fuera de alcance

No consideramos vulnerabilidad de este proyecto, por sí sola:

- Vulnerabilidades del core de FreeScout (repórtalas en [freescout-helpdesk/freescout](https://github.com/freescout-helpdesk/freescout)).
- Instalaciones desplegadas de manera insegura, por ejemplo sin HTTPS en producción.
- Comportamiento interno de la Meta Cloud API.
- Limitaciones ya documentadas en el README que no impliquen un bypass de seguridad.

Si tienes dudas sobre si un caso es de seguridad o de funcionalidad, prioriza el canal privado de seguridad.
