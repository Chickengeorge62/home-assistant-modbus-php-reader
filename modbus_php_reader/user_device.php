<?php
require_once '/phpinc/funktionen.inc.php';
require_once '/vendor/autoload.php';
use \PhpMqtt\Client\MqttClient;

$SerialPort = '/dev/ttyUSB0';
$WR_Adresse = 1;
$WR_Funktionscode = "04";
$WR_Registers = array(
    // ... dein Array bleibt unverändert ...
);

$funktionen = new Funktionen();

function readWR($WRName, $SerialPort, $WR_Adresse, $FunktionsCode, $WR_Registers) {
    global $funktionen;
    global $aktuelleDaten;

    if (!file_exists($SerialPort)) {
        $funktionen->log_schreiben("Serieller Port " . $SerialPort . " nicht gefunden", "XX ", 3);
        throw new Exception("Konnte seriellen Port nicht öffnen.");
    }

    $GeraeteAdresse = str_pad(dechex($WR_Adresse), 2, "0", STR_PAD_LEFT);
    $Timebase = 10000;
    $ModbusCache = array();

    foreach ($WR_Registers as $feldname => $feldspec) {
        $Register = $feldspec[0];
        $DatenTyp = $feldspec[1];
        $RegisterAnzahl = ($DatenTyp == "U32" || $DatenTyp == "I32") ? "0002" : "0001";
        $rc = $funktionen->modbus_rtu_cached_lesen($SerialPort, $GeraeteAdresse, $FunktionsCode, $Register, $RegisterAnzahl, $DatenTyp, $ModbusCache, $Timebase);
        if ($rc !== false) {
            $aktuelleDaten[$WRName . $feldname] = $rc["Wert"] * $feldspec[2];
            $funktionen->log_schreiben($Register . ": " . $feldname . "=" . $aktuelleDaten[$WRName . $feldname] . " " . $DatenTyp, "   ", 5);
        } else {
            $aktuelleDaten[$WRName . $feldname] = 0;
        }
    }

    try {
        $mqtt = new MqttClient('localhost', 1883, 'growatt_client');
        $mqtt->connect();
        foreach ($aktuelleDaten as $key => $value) {
            $mqtt->publish("growatt/{$key}", $value);
        }
        $mqtt->disconnect();
    } catch (Exception $e) {
        $funktionen->log_schreiben("MQTT-Fehler: " . $e->getMessage(), "!  ", 5);
    }
}

readWR("Growatt_", $SerialPort, $WR_Adresse, $WR_Funktionscode, $WR_Registers);
?>
