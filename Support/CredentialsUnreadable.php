<?php

namespace Modules\MetaWhatsApp\Support;

/**
 * Les credencials del canal es van xifrar amb una APP_KEY que ja no és la del
 * FreeScout. No és un error de Meta ni de xarxa, i no es resol reintentant:
 * cal tornar a introduir el token i el secret a la pantalla del canal.
 *
 * Té nom propi perquè el missatge que en surt és la diferència entre un
 * administrador que sap què fer i un que veu una traça d'encriptació.
 */
class CredentialsUnreadable extends \RuntimeException
{
    const HINT = 'Torneu a introduir el token i el secret a la configuració del canal.';
}
