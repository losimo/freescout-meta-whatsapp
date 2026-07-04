# Guía de contribución

[Català](CONTRIBUTING.ca.md) · [English](CONTRIBUTING.md) · [Castellano](CONTRIBUTING.es.md)

MetaWhatsApp es un proyecto abierto con mantenimiento responsable. La buena fe es el punto de partida, pero la apertura no significa aceptar cualquier cambio sin criterio. El proyecto tiene una arquitectura definida, un alcance de MVP claro y unas líneas rojas que hay que respetar.

## Filosofía

Queremos contribuciones útiles, bien argumentadas y fáciles de mantener. Los cambios pequeños y evidentes son bienvenidos; los cambios que afectan a arquitectura, seguridad, UX del core o alcance funcional deben hablarse antes.

La peor experiencia para todos es una PR grande y trabajada que hay que rechazar después por una decisión que se podía haber aclarado en una issue.

## Antes de empezar

Elige el canal correcto:

| Tienes... | Haz... |
|---|---|
| Un bug reproducible | Abre una issue de bug. |
| Una pregunta de uso | Abre una issue de pregunta. |
| Una propuesta de cambio o funcionalidad | Abre una issue de propuesta. |
| Una vulnerabilidad | Nunca una issue pública; usa el canal privado de seguridad. |

Para bugs pequeños y evidentes, una PR directa es bienvenida. Para cualquier otra cosa que pueda tocar arquitectura, seguridad, UX del core o alcance, abre una issue antes de trabajar la PR.

## Qué es bienvenido

- Correcciones de bugs con test de regresión.
- Mejoras de documentación y traducciones.
- Tests adicionales para comportamiento existente.
- Mejoras de UX dentro del alcance actual.
- Compatibilidad con nuevas versiones de FreeScout, si no altera el modelo del módulo.

## Qué requiere discusión previa

Requieren discusión previa obligatoria:

- Funcionalidades nuevas, por pequeñas que sean.
- Cambios en el esquema de base de datos.
- Dependencias nuevas.
- Refactors que toquen más de un componente.
- Cualquier cambio en las rutas sensibles descritas más abajo.

## Líneas rojas

Estas no son negociables por PR directa:

1. Zero-core: no se modifica el core de FreeScout.
2. No se relaja la seguridad para simplificar la UX.
3. El modelo fail-closed del webhook no se rompe.
4. No se añade funcionalidad fuera del MVP sin acuerdo previo.
5. No se introducen dependencias o abstracciones prematuras sin una justificación fuerte.

## Cambios sensibles

Estos ámbitos requieren discusión previa siempre, aunque la modificación parezca pequeña:

- El webhook y la verificación HMAC.
- El manejo de credenciales y secretos.
- Los jobs y la cola.
- Cualquier punto que dependa de la semántica del core de FreeScout, como hooks, ventana de deshacer, tipos de conversación o `customer_channel`.

Las PR directas sobre estas rutas sin issue vinculada se pueden cerrar sin fusionar. No es desconfianza; es que en estos puntos los errores no siempre se ven en el diff, sino en producción.

## Requisitos mínimos de una PR

Cualquier PR debería incluir:

- Descripción clara del cambio.
- Motivación.
- Impacto funcional.
- Impacto de seguridad, si procede.
- Tests que cubran el cambio, o una justificación explícita si no los hay.
- Documentación actualizada, si hace falta.
- Código coherente con el estilo existente.

Si el cambio altera alguna limitación o expectativa documentada, la documentación también debe actualizarse.

## Política de discusiones

Separa siempre estos casos:

- Bug.
- Pregunta.
- Propuesta.
- Vulnerabilidad.

Esto ayuda a mantener las conversaciones ordenadas y agiliza la toma de decisiones.

## Tono de mantenimiento

Las revisiones se harán de manera clara, franca y orientada a producto. Las propuestas se aceptarán por impacto, no por volumen de trabajo. Los desacuerdos se pueden discutir, pero hay que aportar argumentos nuevos y concretos.

## Tests

La suite se puede ejecutar con:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Modules/MetaWhatsApp/Tests
```

Los tests trabajan contra la base de datos de la instalación con rollback por test y no dejan datos persistentes.

## Nota final

Si tienes dudas sobre si una idea encaja en el MVP, si toca una ruta sensible o si puede tener implicaciones de seguridad, abre una issue antes de programar.
