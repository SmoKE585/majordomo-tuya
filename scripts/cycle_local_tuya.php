<?php
/**
 * Цикл локального опроса Tuya (прямое подключение к устройствам).
 *
 * 2026-10-23 — цикл сам отключается, когда ни одно устройство не получает
 * статус локально, и отмечает heartbeat перед каждым устройством: один
 * медленный сокет больше не выглядит как зависание всего цикла.
 */

chdir(dirname(__FILE__) . '/../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

set_time_limit(0);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'control_modules/control_modules.class.php');

$ctl = new control_modules();

include_once(DIR_MODULES . 'tuya/tuya.class.php');

$cycle_name = str_replace('.php', '', basename(__FILE__));

$tuya_module = new tuya();
$tuya_module->getConfig();
$tuya_module->cycleName = $cycle_name;

$heartbeat = static function () use ($cycle_name) {
    setGlobal($cycle_name . 'Run', time(), 1);
};

$heartbeat();

echo date('H:i:s') . ' Запуск ' . basename(__FILE__) . PHP_EOL;

if (!$tuya_module->cycleIsNeeded($cycle_name)) {
    echo date('H:i:s') . ' Цикл отключён: нет устройств с локальным статусом.' . PHP_EOL;
    $tuya_module->disablePluginCycle($cycle_name, 'ни одно устройство не получает статус локально');
    exit(0);
}

$latest_check = 0;
$latest_disc = 0;
$cycle_debug = false;

$tuya_local_interval = 5;

if (!empty($tuya_module->config['TUYA_LOCAL_INTERVAL'])) {
    $tuya_local_interval = max(1, (int)$tuya_module->config['TUYA_LOCAL_INTERVAL']);
}

if (!empty($tuya_module->config['TUYA_CYCLE_DEBUG'])) {
    $cycle_debug = $tuya_module->config['TUYA_CYCLE_DEBUG'];
}


echo date('H:i:s') . ' Инициализация Tuya (локальный режим)' . PHP_EOL;
echo date('H:i:s') . " Период опроса — {$tuya_local_interval} сек." . PHP_EOL;


$save_dsp = array();
$dps_null = array();
$latest_needs_check = 0;

while (1) {
    $heartbeat();

    // Настройки могли измениться без перезапуска: если локальный статус
    // больше не нужен ни одному устройству, цикл отключается сам.
    if ((time() - $latest_needs_check) >= 60) {
        $latest_needs_check = time();
        $tuya_module->getConfig();
        if (!$tuya_module->cycleIsNeeded($cycle_name)) {
            echo date('H:i:s') . ' Цикл отключён: нет устройств с локальным статусом.' . PHP_EOL;
            $tuya_module->disablePluginCycle($cycle_name, 'ни одно устройство больше не получает статус локально');
            exit(0);
        }
    }

    if ((time() - $latest_disc) >= 5 * 60) {
        $latest_disc = time();
        $devices = (array)SQLSelect("SELECT ID, TITLE, LOCAL_KEY, DEV_ID, DEV_IP, UUID, '' as MAC, 0 as 'ZIGBEE', SEND12, FLAGS12, TUYA_VER FROM tudevices WHERE LOCAL_KEY!='' and DEV_IP!='' and STATUS=1 ORDER BY DEV_ID");
        $gw_devices = (array)SQLSelect("SELECT d.ID, d.TITLE, gw.LOCAL_KEY, d.DEV_ID, gw.DEV_IP, d.UUID, d.MAC, 1 as 'ZIGBEE', d.SEND12, d.FLAGS12, gw.TUYA_VER FROM tudevices d INNER JOIN tudevices gw ON d.MESH_ID = gw.DEV_ID WHERE gw.LOCAL_KEY!='' and gw.DEV_IP!='' and d.STATUS=1");
        $devices = array_merge($devices, $gw_devices);

        if ($cycle_debug) {
            debmes(date('H:i:s') . ' Tuya: добавлено ' . count($devices) . ' устройств для локального мониторинга');
            echo date('H:i:s') . ' Tuya: добавлено ' . count($devices) . ' устройств для локального мониторинга' . PHP_EOL;
        }
    }


    if ((time() - $latest_check) >= $tuya_local_interval) {
        $latest_check = time();
        $heartbeat();
        if ($cycle_debug) {
            echo 'Запуск проверки статуса ' . date('H:i:s') . PHP_EOL;
        }

        try {
        foreach ((array)$devices as $device) {
            // Один сокет может ждать до нескольких секунд — отмечаемся до него.
            $heartbeat();
            if ($cycle_debug) {
                debmes(date('H:i:s') . ' Tuya: получение локального статуса ' . $device['TITLE']);
                echo 'Проверка статуса: ' . $device['TITLE'] . ' ' . date('H:i:s') . PHP_EOL;
            }

            $command = 'STATUS';

            $local_key = $device['LOCAL_KEY'];
            $dev_id = $device['DEV_ID'];
            $local_ip = $device['DEV_IP'];
            $tuya_ver = $device['TUYA_VER'];

            if (ping($local_ip)) {
                $save_dps[$device['ID']]['attempt'] = 0;

                if (!isset($save_dps[$device['ID']]['online']) || $save_dps[$device['ID']]['online'] == 0) {
                    if ($cycle_debug) {
                        debmes('Устройство ' . $device['TITLE'] . ' в сети');
                    }
                    $save_dps[$device['ID']]['online'] = 1;
                    $tuya_module->processCommand($device['ID'], 'online', 1);
                }

                $hexByte = "0a";
                if ($device['ZIGBEE'] == 0) {
                    $json = '{"gwId":"' . $dev_id . '","devId":"' . $dev_id . '"}';
                    $cid = '';
                } else {
                    $json = '{"cid":"' . $device['UUID'] . '"}';
                    $cid = $device['UUID'];
                }

                if ($tuya_ver == '3.4') {
                    $dps = '{}';
                    $dps12 = $device['SEND12'] ? $device['FLAGS12'] : '';
                    $result = $tuya_module->TuyaLocalMsg34('STATUS', $dev_id, $local_key, $local_ip, $dps, $cid, $dps12);
                    $status = json_decode($result);

                } elseif ($tuya_ver == '3.5') {
                    $dps = '{}';
                    $dps12 = $device['SEND12'] ? $device['FLAGS12'] : '';
                    $result = $tuya_module->tuyaLocalMsg35('STATUS', $dev_id, $local_key, $local_ip, $dps, $cid, $dps12);
                    $status = json_decode($result);

                } else {

                    $payload = $tuya_module->TuyaLocalEncrypt($hexByte, $json, $local_key, $tuya_ver);

                    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 1, "usec" => 0));

                    $buf = '';

                    if (socket_connect($socket, $local_ip, 6668)) {
                        if ($device['SEND12']) {
                            $payload_12 = $tuya_module->TuyaLocalEncrypt('12', $device['FLAGS12'], $local_key, $tuya_ver);
                            $send = socket_send($socket, $payload_12, strlen($payload_12), 0);

                            if ($send != strlen($payload_12)) {
                                debmes('Ошибка отправки 12. ' . date('y-m-d h:i:s') . ' отправлено ' . $send . ' из ' . strlen($payload) . ', ip ' . $local_ip);
                            }

                            $reciv = socket_recv($socket, $buf, 2048, MSG_WAITALL);
                            $result = substr($buf, 63, -8);

                            if ($tuya_ver != '3.1') {
                                $result = openssl_decrypt($result, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
                            }

                            if ($cycle_debug) {
                                debmes('Ответ на 12: ' . $result);
                            }
                        }

                        for ($i = 0; $i < 1; $i++) {
                            $send = socket_send($socket, $payload, strlen($payload), 0);
                            if ($send != strlen($payload)) {
                                echo date('y-m-d h:i:s') . ' отправлено ' . $send . ' из ' . strlen($payload) . ', ip ' . $local_ip . '<BR>';
                            }
                            $buf = '';
                            $reciv = socket_recv($socket, $buf, 2048, 0);
                            if ($buf != '') break;
                            sleep(1);
                        }

                    } else {
                        $err = socket_last_error($socket);
                        debmes('Не удалось подключиться к устройству ' . $device['TITLE'] . ' (' . $dev_id . ', ' . $local_ip . '): ' . socket_strerror($err), 'tuya');
                    }


                    $result = substr($buf, 20, -8);

                    if ($tuya_ver != '3.1') {
                        $result = openssl_decrypt($result, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
                    }

                    $status = json_decode($result);
                    if ($cycle_debug) {
                        debmes(date('H:i:s') . ' Tuya: статус=' . $result);
                    }

                    if ($result == 'json obj data unvalid') {
                        if ($cycle_debug) {
                            debmes(date('H:i:s') . ' Tuya: получение альт. статуса');
                        }

                        $hexByte = "0d";

                        if (isset($dps_null[$device['DEV_ID']])) {
                            $dps = $dps_null[$device['DEV_ID']];
                        } else {
                            $sql = "SELECT TITLE from tucommands WHERE DEVICE_ID='" . (int)$device['ID'] . "' AND ceil(TITLE)!=0 ORDER BY CAST(TITLE AS UNSIGNED)";
                            $command = SQLSelect($sql);

                            $dps = '';
                            foreach ($command as $d) {
                                $dps .= ',' . '"' . $d['TITLE'] . '":null';
                            }
                            $dps = '{' . substr($dps, 1) . '}';
                            $dps_null[$device['DEV_ID']] = $dps;
                        }

                        if ($cycle_debug) {
                            debmes('Dps:' . $dps);
                        }


                        if ($device['ZIGBEE'] == 0) {
                            $json = '{"devId":"' . $dev_id . '","uid":"","t":"' . time() . '","dps": ' . $dps . '}';
                        } else {
                            $json = '{"dps":' . $dps . ', "t": "' . time() . '","cid":"' . $device['MAC'] . '"}';
                        }

                        $payload = $tuya_module->TuyaLocalEncrypt($hexByte, $json, $local_key);

                        $buf = '';

                        $send = socket_send($socket, $payload, strlen($payload), 0);
                        if ($send != strlen($payload)) {
                            echo date('y-m-d h:i:s') . ' отправлено ' . $send . ' из ' . strlen($payload) . ', ip ' . $local_ip . '<BR>';
                        }
                        $buf = '';
                        $reciv = socket_recv($socket, $buf, 2048, 0);

                        $result = substr($buf, 35, -8);
                        $result = openssl_decrypt($result, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);

                        $status = json_decode($result);

                        if ($cycle_debug) {
                            // Локальный ключ — секрет: в журнал попадает только
                            // длина ответа, без ключа и сырого пакета.
                            debmes('Tuya: ответ ' . strlen((string)$buf) . ' байт, альт. статус=' . $result, 'tuya');
                        }
                    }

                    socket_close($socket);
                }

                if (isset($status->dps)) {
                    $dps = $status->dps;
                    foreach ($dps as $k => $d) {
                        if (is_bool($d)) {
                            $d = ($d) ? 1 : 0;
                        }
                        if (!isset($save_dps[$device['ID']][$k]) || $save_dps[$device['ID']][$k] != $d) {
                            if ($cycle_debug) {
                                debmes(date('H:i:s') . ' Tuya: сохранено: ' . $k . '=' . $d);
                            }

                            $save_dps[$device['ID']][$k] = $d;
                            $tuya_module->processCommand($device['ID'], $k, $d);
                        }
                    }
                }
            } else {

                if (isset($save_dps[$device['ID']]['attempt'])) {
                    $save_dps[$device['ID']]['attempt'] += 1;
                } else {
                    $save_dps[$device['ID']]['attempt'] = 1;
                }
                if ((!isset($save_dps[$device['ID']]['online']) || $save_dps[$device['ID']]['online'] == 1) && $save_dps[$device['ID']]['attempt'] == 5) {
                    if ($cycle_debug) {
                        debmes('Устройство ' . $device['TITLE'] . ' не в сети');
                    }
                    $save_dps[$device['ID']]['online'] = 0;
                    $tuya_module->processCommand($device['ID'], 'online', 0);
                }

            }
        }
        } catch (Throwable $e) {
            // Ошибка одного устройства не должна останавливать весь цикл.
            DebMes('Tuya local: ' . $e->getMessage(), 'cycle_local_tuya', 'error');
            echo date('H:i:s') . ' Ошибка локального опроса: ' . $e->getMessage() . PHP_EOL;
        }

    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        echo date('H:i:s') . ' Остановка по команде REBOOT или ONETIME: ' . basename(__FILE__) . PHP_EOL;
        exit(0);
    }


    sleep(1);
}

echo date('H:i:s') . ' Неожиданное завершение цикла' . PHP_EOL;

DebMes('Неожиданное завершение цикла: ' . basename(__FILE__), 'cycle_local_tuya', 'error');
