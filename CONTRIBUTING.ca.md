# Guia de contribució

[Català](CONTRIBUTING.ca.md) · [English](CONTRIBUTING.md) · [Castellano](CONTRIBUTING.es.md)

MetaWhatsApp és un projecte obert amb manteniment responsable. La bona fe és el punt de partida, però l'obertura no vol dir acceptar qualsevol canvi sense criteri. El projecte té una arquitectura definida, un abast de MVP clar i unes línies vermelles que cal respectar.

## Filosofia

Volem contribucions útils, ben argumentades i fàcilment mantenibles. Els canvis petits i evidents són benvinguts; els canvis que afecten arquitectura, seguretat, UX del core o abast funcional s'han de parlar abans.

La pitjor experiència per a tothom és una PR gran i treballada que s'ha de rebutjar després per una decisió que es podia aclarir en una issue.

## Abans de començar

Tria el canal correcte:

| Tens... | Fes... |
|---|---|
| Un bug reproduïble | Obre una issue de bug. |
| Una pregunta d'ús | Obre una issue de pregunta. |
| Una proposta de canvi o funcionalitat | Obre una issue de proposta. |
| Una vulnerabilitat | Mai una issue pública; usa el canal privat de seguretat. |

Per a bugs petits i evidents, una PR directa és benvinguda. Per a qualsevol altra cosa que pugui tocar arquitectura, seguretat, UX del core o abast, obre una issue abans de treballar la PR.

## Què és benvingut

- Correccions de bugs amb test de regressió.
- Millores de documentació i traduccions.
- Tests addicionals per a comportament existent.
- Millores d'UX dins de l'abast actual.
- Compatibilitat amb noves versions de FreeScout, si no altera el model del mòdul.

## Què requereix discussió prèvia

Requereixen discussió prèvia obligatòria:

- Funcionalitats noves, encara que siguin petites.
- Canvis a l'esquema de base de dades.
- Dependències noves.
- Refactors que toquin més d'un component.
- Qualsevol canvi als camins sensibles descrits més avall.

## Línies vermelles

Aquestes no són negociables per PR directa:

1. Zero-core: no es modifica el core de FreeScout.
2. No es relaxa la seguretat per simplificar la UX.
3. El model fail-closed del webhook no es trenca.
4. No s'afegeix funcionalitat fora del MVP sense acord previ.
5. No s'introdueixen dependències o abstraccions prematures sense una justificació forta.

## Canvis sensibles

Aquests àmbits requereixen discussió prèvia sempre, encara que la modificació sembli petita:

- El webhook i la verificació HMAC.
- El maneig de credencials i secrets.
- Els jobs i la cua.
- Qualsevol punt que depengui de la semàntica del core de FreeScout, com hooks, finestra d'undo, tipus de conversa o `customer_channel`.

Les PR directes sobre aquests camins sense issue vinculada es poden tancar sense fusió. No és desconfiança; és que en aquests punts els errors no sempre es veuen al diff, sinó en producció.

## Requisits mínims d'una PR

Qualsevol PR hauria d'incloure:

- Descripció clara del canvi.
- Motivació.
- Impacte funcional.
- Impacte de seguretat, si escau.
- Tests que cobreixin el canvi, o una justificació explícita si no n'hi ha.
- Documentació actualitzada, si cal.
- Codi coherent amb l'estil existent.

Si el canvi altera alguna limitació o expectativa documentada, la documentació també s'ha d'actualitzar.

## Política de discussions

Separa sempre aquests casos:

- Bug.
- Pregunta.
- Proposta.
- Vulnerabilitat.

Això ajuda a mantenir les converses ordenades i fa més ràpida la presa de decisions.

## To de manteniment

Les revisions es faran de manera clara, franca i orientada a producte. Les propostes s'acceptaran per impacte, no per volum de feina. Els desacords es poden discutir, però cal aportar arguments nous i concrets.

## Tests

La suite es pot executar amb:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Modules/MetaWhatsApp/Tests
```

Els tests treballen contra la base de dades de la instal·lació amb rollback per test i no deixen dades persistents.

## Nota final

Si tens dubtes sobre si una idea encaixa en el MVP, si toca una ruta sensible o si pot tenir implicacions de seguretat, obre una issue abans de programar.
