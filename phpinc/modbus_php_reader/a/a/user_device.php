<?php
require_once '/phpinc/funktionen.inc.php';

$SerialPort = '/dev/ttyUSB0';
$WR_Adresse = 1;
$WR_Funktionscode = "04";
$WR_Registers = array(
    "Status" => array(0, "U16", 1),
    "PV_Spannung1_V" => array(1, "U16", 0.1),
    "PV_Spannung2_V" => array(2, "U16", 0.1),
    "PV_Leistung1_W" => array(3, "U32", 0.1),
    "PV_Leistung2_W" => array(5, "U32", 0.1),
    "Load_W" => array(9, "U32", 0.1),
    "AC_Ladeleistung_W" => array(13, "U32", 0.1),
    "Batteriespannung_V" => array(31002, "U16", 0.1),
    "Batterie_SOC" => array(31004, "U16", 1),
    "AC_IN_V" => array(10, "U16", 0.1),
    "Inverter_Temp_C" => array(25, "U16", 0.1),
    "DCDC_Temp_C" => array(26, "U16", 0.1),
    "Load_percent" => array(27, "U16", 0.1),
    "Load_A" => array(34, "U16", 0.1),
    "AC_IN_W" => array(36, "U32", 0.1),
    "Fehler_Bits" => array(40, "U16", 0.1),
    "Warnung_Bits" => array(41, "U16", 0.1),
    "Fehler_Wert" => array(42, "U16", 0.1),
    "Warnung_Wert" => array(43, "U16", 0.1),
    "PV_Energie_heute_KWh" => array(48, "U32", 0.1),
    "PV_Energie_total_KWh" => array(50, "U32", 0.1),
    "Batteriestrom_W" => array(77, "I32", 0.1),
    "Lüfterdrehzahl_prozent" => array(82, "U16", 1)
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
        $RegisterAnzahl = "0001";
        if ($DatenTyp == "U32" || $DatenTyp == "I32") {
            $RegisterAnzahl = "0002";
        }
        $rc = $funktionen->modbus_rtu_cached_lesen($SerialPort, $GeraeteAdresse, $FunktionsCode, $Register, $RegisterAnzahl, $DatenTyp, $ModbusCache, $Timebase);
        if ($rc !== false) {
            $aktuelleDaten[$WRName . $feldname] = $rc["Wert"] * $feldspec[2];
            $funktionen->log_schreiben($Register . ": " . $feldname . "=" . $aktuelleDaten[$WRName . $feldname] . " " . $DatenTyp, "   ", 5);
        } else {
            $aktuelleDaten[$WRName . $feldname] = 0;
        }
    }

    // MQTT-Integration
    require_once '/vendor/autoload.php';
    use \PhpMqtt\Client\MqttClient;

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
