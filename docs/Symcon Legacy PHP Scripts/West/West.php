<?php
declare(strict_types=1);
require_once IPS_GetScriptFile(40031); // Hier die ID deines Hilfsskripts!

$cfg = [
    'aktorAutomatik'        => [
                                40273=>0,
                                32863=>0
                                ],
    'aktorAutomatikOeffnen' => [
                                33239=>0,
                                56707=>0
                                ],
    'debugID'               => 41641,
    'fensterRichtungID'     => 49659,
    'winkelRechtsID'        => 32701,
    'winkelLinksID'         => 11455,
    'hoeheDachID'           => 32401,
    'dachvorsprungID'       => 28131,
    'endwinkelID'           => 26559,
    'fensterBrettID'        => 44983,
    'letzteBewegungID'      => 33983,
    'protokollID'           => 55827,
    'autoID'                => 31690,
    'beschattID'            => 11940,
    'luxOKID'               => 44504,
    'tempOKID'              => 58080,
    'azimutOKID'            => 18863,
    'elevOKID'              => 51691,
    'sperrRestID'           => 50222,
    'letzterModusID'        => 36050,
    'elevationID'           => 52571,
    'azimutID'              => 32562,
    'helligkeitID'          => 39578,
    'tempAussenID'          => 29425,
    'alternativID'          => 54284,
    'anteilSonneID'         => 28703,
    'fruehestensSecID'      => 26057,
    'spaetestensSecID'      => 50517,
    'sperrzeitID'           => 31081,
    'kritTempSofort'        => 30.0,
    'tempHystEin'           => 22.5,
    'tempHystAus'           => 21.5,
    'luxHystEin'            => 21000,
    'luxHystAus'            => 19000,
    'monitorBoxID'          => 36156,
];

StarteBeschattung($cfg);
