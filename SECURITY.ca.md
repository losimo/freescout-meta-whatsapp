# Política de seguretat

[Català](SECURITY.ca.md) · [English](SECURITY.md) · [Castellano](SECURITY.es.md)

MetaWhatsApp toca, per definició, les peces més sensibles d'una instal·lació de FreeScout: credencials de l'API de Meta, un webhook exposat públicament, cues de treball i una integració externa que mou converses privades de clients. Per això els reports responsables de seguretat són especialment importants per a aquest projecte, i ens els prenem seriosament.

## Versions suportades

| Versió | Suport de seguretat |
|---|---|
| 1.x (branca principal) | Sí |
| Versions anteriors | No |

Aquest mòdul està pensat per a FreeScout 1.8.x. Les vulnerabilitats en versions de FreeScout no suportades pel projecte upstream queden fora d'abast d'aquest projecte.

## Com reportar una vulnerabilitat

No obris issues ni pull requests públiques per a vulnerabilitats de seguretat. Una publicació abans de tenir el fix posa en risc totes les instal·lacions que fan servir el mòdul.

Canals privats, per ordre de preferència:

1. GitHub → Security → Report a vulnerability, en aquest repositori.
2. Correu electrònic: losimo@gmail.com

## Què ha d'incloure el report

Intenta incloure, com a mínim:

- Versió del mòdul, versió de FreeScout i versió de PHP.
- Descripció clara de la vulnerabilitat i del seu impacte.
- Passos de reproducció o prova de concepte, si en tens.
- Qualsevol configuració rellevant per reproduir-la.

No enviïs secrets, tokens, contrasenyes ni dades privades que no siguin estrictament necessàries.

## Compromís de resposta

Aquest projecte es manté en temps personal. No prometem un SLA formal, però ens comprometem a:

- Confirmar la recepció, normalment dins d'una setmana.
- Fer una primera avaluació i proposar un pla d'acció, normalment dins de 30 dies.
- Coordinar la correcció i la divulgació amb la persona reportadora abans de cap publicació pública.

## Què considerem vulnerabilitat

Considerem incidència de seguretat, entre d'altres:

- Qualsevol bypass de la verificació HMAC del webhook.
- Exposició de credencials, tokens o secrets en logs, respostes HTTP o excepcions.
- Injecció o manipulació via payload del webhook.
- Misatribució o injecció creuada entre comptes o canals.
- Bypass d'autorització a les pantalles d'administració.
- CSRF en accions sensibles d'administració.

## Fora d'abast

No considerem vulnerabilitat d'aquest projecte, per si sola:

- Vulnerabilitats del core de FreeScout (reporta-les a [freescout-helpdesk/freescout](https://github.com/freescout-helpdesk/freescout)).
- Instal·lacions desplegades de manera insegura, per exemple sense HTTPS en producció.
- Comportament intern de la Meta Cloud API.
- Limitacions ja documentades al README que no impliquen un bypass de seguretat.

Si tens dubtes sobre si un cas és de seguretat o de funcionalitat, prioritza el canal privat de seguretat.
