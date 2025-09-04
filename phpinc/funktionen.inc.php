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
        $serial = @dio_open($serialPort, O_RDWR);
        if (!$serial) {
            $this->log_schreiben("Konnte seriellen Port " . $serialPort . " nicht öffnen", "!  ", 5);
            return false;
        }

        dio_tcsetattr($serial, array(
            'baud' => 9600,
            'bits' => 8,
            'stop' => 1,
            'parity' => 0
        ));

        $frame = pack('C', hexdec($GeraeteAdresse));
        $frame .= pack('C', hexdec($FunktionsCode));
        $frame .= pack('n', $RegisterAdresse);
        $frame .= pack('n', $ModbusCacheSize);
        $crc = $this->calculateCRC($frame);
        $frame .= $crc;

        dio_write($serial, $frame);

        $response = '';
        $timeout = 1;
        $startTime = microtime(true);
        while ((microtime(true) - $startTime) < $timeout) {
            $data = dio_read($serial, 1024);
            if ($data !== false) {
                $response .= $data;
            }
            if (strlen($response) >= (3 + $ModbusCacheSize * 2 + 2)) {
                break;
            }
        }

        dio_close($serial);

        if (strlen($response) < 5) {
            $this->log_schreiben("Keine oder ungültige Antwort von Gerät " . $GeraeteAdresse, "!  ", 5);
            return false;
        }

        $responseCRC = substr($response, -2);
        $calculatedCRC = $this->calculateCRC(substr($response, 0, -2));
        if ($responseCRC !== $calculatedCRC) {
            $this->log_schreiben("CRC-Fehler bei Modbus-Antwort", "!  ", 5);
            return false;
        }

        $byteCount = ord($response[2]);
        $data = substr($response, 3, $byteCount);
        for ($i = 0; $i < $byteCount / 2; $i++) {
            $ModbusCache[$RegisterAdresse + $i] = bin2hex(substr($data, $i * 2, 2));
        }

        $ModbusCache["gelesen"] = new DateTime();
        return true;
    }

    function calculateCRC($data) {
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
