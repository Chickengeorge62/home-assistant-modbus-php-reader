<?php
class Funktionen {
    function modbus_rtu_cached_lesen($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $RegisterAnzahl, $DatenTyp, &$ModbusCache, $Timebase = 600000) {
        $Daten = array();
        $DatenOK = true;
        $ModbusCacheSize = 32;

        if (isset($ModbusCache["gelesen"])) {
            $jetzt = new DateTime();
            $interval = $ModbusCache["gelesen"]->diff($jetzt);
            if ($interval->format('%s') > 30) {
                $DatenOK = $this->fillModbusCache($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $ModbusCacheSize, $DatenTyp, $ModbusCache, $Timebase);
            }
            if (!isset($ModbusCache[$RegisterAdresse])) {
                $DatenOK = $this->fillModbusCache($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $ModbusCacheSize, $DatenTyp, $ModbusCache, $Timebase);
            } elseif (!isset($ModbusCache[$RegisterAdresse + $RegisterAnzahl - 1])) {
                $DatenOK = $this->fillModbusCache($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $ModbusCacheSize, $DatenTyp, $ModbusCache, $Timebase);
            }
        } else {
            $DatenOK = $this->fillModbusCache($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $ModbusCacheSize, $DatenTyp, $ModbusCache, $Timebase);
        }

        if ($DatenOK) {
            $HexString = "";
            for ($i = $RegisterAdresse; $i < ($RegisterAdresse + $RegisterAnzahl); $i++) {
                if (!isset($ModbusCache[$i])) {
                    $HexString .= '0000';
                } else {
                    $HexString .= $ModbusCache[$i];
                }
            }
            switch ($DatenTyp) {
                case "String":
                    $Daten["Wert"] = $this->Hex2String(substr($HexString, 0, strpos($HexString, '00')));
                    break;
                case "U8":
                    $Daten["Wert"] = hexdec($HexString);
                    break;
                case "U16":
                    $Daten["Wert"] = hexdec($HexString);
                    break;
                case "U32":
                    $Daten["Wert"] = hexdec($HexString);
                    break;
                case "U32S":
                    $highBytes = substr($HexString, 4, 4);
                    $lowBytes = substr($HexString, 0, 4);
                    $Daten["Wert"] = hexdec($highBytes . $lowBytes);
                    break;
                case "I16":
                    $Daten["Wert"] = hexdec($HexString);
                    if ($Daten["Wert"] > 32767) {
                        $Daten["Wert"] = $Daten["Wert"] - 65536;
                    }
                    break;
                case "I32":
                    $Daten["Wert"] = $this->hexdecs($HexString);
                    break;
                case "I32S":
                    $highBytes = substr($HexString, 4, 4);
                    $lowBytes = substr($HexString, 0, 4);
                    $Daten["Wert"] = $this->hexdecs($highBytes . $lowBytes);
                    break;
                case "Float32":
                    $Daten["Wert"] = round($this->hex2float32($HexString), 2);
                    break;
                case "Hex":
                    $Daten["Wert"] = $HexString;
                    break;
                case "ASCII":
                    $Daten["Wert"] = chr(hexdec($HexString));
                    break;
                case "Zeichenkette":
                    $Daten["Wert"] = hex2bin($HexString);
                    break;
                default:
                    $Daten["Wert"] = 0;
                    break;
            }
            return $Daten;
        } else {
            $this->log_schreiben("Konnte Modbus-Register " . $RegisterAdresse . " nicht von Gerät " . $GeraeteAdresse . " via RTU lesen", "!  ", 5);
            return false;
        }
    }

    function fillModbusCache($serialPort, $GeraeteAdresse, $FunktionsCode, $RegisterAdresse, $ModbusCacheSize, $DatenTyp, &$ModbusCache, $Timebase) {
        require_once '/vendor/autoload.php';
        use PhpModbus\ModbusMaster;

        try {
            // Erstelle eine ModbusMaster-Instanz für RTU
            $modbus = new ModbusMaster('localhost', 'RTU', $serialPort, 9600, 8, 1, 0);
            $modbus->setTimeout(1); // Timeout auf 1 Sekunde setzen

            // Lese Register basierend auf Funktionscode
            if ($FunktionsCode == '04') {
                $recData = $modbus->readInputRegisters(hexdec($GeraeteAdresse), $RegisterAdresse, $ModbusCacheSize);
            } elseif ($FunktionsCode == '03') {
                $recData = $modbus->readHoldingRegisters(hexdec($GeraeteAdresse), $RegisterAdresse, $ModbusCacheSize);
            } else {
                $this->log_schreiben("Nicht unterstützter Funktionscode: " . $FunktionsCode, "!  ", 5);
                return false;
            }

            // Speichere Daten im Cache
            for ($i = 0; $i < count($recData) / 2; $i++) {
                $ModbusCache[$RegisterAdresse + $i] = sprintf("%04X", ($recData[$i * 2] << 8) | $recData[$i * 2 + 1]);
            }

            $ModbusCache["gelesen"] = new DateTime();
            return true;
        } catch (Exception $e) {
            $this->log_schreiben("Modbus-Fehler: " . $e->getMessage(), "!  ", 5);
            return false;
        }
    }

    function calculateCRC($data) {
        // Nicht mehr benötigt mit phpmodbus, aber für Kompatibilität beibehalten
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x0001) {
                    $crc >>= 1;
                    $crc ^= 0xA001;
                } else {
                    $crc >>= 1;
                }
            }
        }
        return pack('v', $crc);
    }

    function hex2float32($hex) {
        $bin = hex2bin($hex);
        $unpacked = unpack('f', strrev($bin));
        return $unpacked[1];
    }

    function hexdecs($hex) {
        $x = hexdec($hex);
        if ($x > 0x7FFFFFFF) {
            $x -= 0x100000000;
        }
        return $x;
    }

    function Hex2String($hex) {
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $string .= chr(hexdec(substr($hex, $i, 2)));
        }
        return $string;
    }

    function log_schreiben($message, $prefix, $level) {
        file_put_contents('/share/modbus.log', date('Y-m-d H:i:s') . " $prefix$message\n", FILE_APPEND);
    }
}
?>
