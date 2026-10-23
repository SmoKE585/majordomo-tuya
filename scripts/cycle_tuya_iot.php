<?php
/**
 * Цикл Tuya IoT (MQTT).
 *
 * 2026-10-23 — цикл включается только когда настроена IoT-платформа и есть
 * устройства с источником статуса «MQTT»; иначе он сам переводится в
 * состояние `disabled`, чтобы супервизор не перезапускал его и не писал
 * `cycle_hang`/`cycle_stop`. Исправлены неинициализированные переменные,
 * падения на пустом ответе API, отсутствие переподключения MQTT и пустой UID
 * (использовался UID веб-аккаунта вместо UID IoT-платформы).
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
include_once(DIR_MODULES . 'tuya/libMQTT/Client.php');

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
    echo date('H:i:s') . ' Цикл отключён: IoT-платформа не настроена.' . PHP_EOL;
    $tuya_module->disablePluginCycle($cycle_name, 'IoT-платформа не настроена или нет устройств с источником статуса MQTT');
    exit(0);
}

$result = null;
$login_failures = 0;

// Вход в IoT-облако переподключается внутри цикла: завершение процесса
// заставляло супервизор запускать его снова и писал `cycle_stop` на каждую
// неудачную попытку. Здесь процесс остаётся живым и ждёт с heartbeat.
while (1) {
    $heartbeat();
    $result = $tuya_module->Tuya_IOT_GET('/v1.0/token?grant_type=1', true);
    if ($result && !empty($result->success) && !empty($result->result->access_token)) {
        break;
    }

    $login_failures++;
    $errorMsg = ($result && isset($result->msg)) ? $result->msg : 'ошибка сети или неверные ключи доступа';
    if ($login_failures === 1 || ($login_failures % 10) === 0) {
        DebMes("Не удалось войти в облако IoT (MQTT-цикл, попытка {$login_failures}): {$errorMsg}. Проверьте Client ID и Client Secret на developer.tuya.com.", 'tuya', 'error');
        echo date('H:i:s') . " Ошибка входа в IoT: {$errorMsg}" . PHP_EOL;
    }

    for ($i = 0; $i < 30; $i++) {
        $heartbeat();
        sleep(1);
    }

    $tuya_module->getConfig();
    if (!$tuya_module->cycleIsNeeded($cycle_name)) {
        echo date('H:i:s') . ' Цикл отключён: IoT-платформа выключена в настройках.' . PHP_EOL;
        $tuya_module->disablePluginCycle($cycle_name, 'IoT-платформа выключена в настройках модуля');
        exit(0);
    }
}

$tuya_module->config['TUYA_ACCESS_TOKEN'] = $result->result->access_token;
$tuya_module->config['TUYA_REFRESH_TOKEN'] = $result->result->refresh_token ?? '';
$tuya_module->config['TUYA_TOKEN_EXPIRE_TIME'] = (int)($result->result->expire_time ?? 0) + time();
$tuya_module->config['TUYA_IOT_UID'] = $result->result->uid ?? '';
$tuya_module->saveConfig();

$cycle_debug = !empty($tuya_module->config['TUYA_CYCLE_DEBUG']);

$devices = array();
$mqtt_devices = (array)SQLSelect("SELECT ID, DEV_ID FROM tudevices WHERE STATUS=2;");
foreach ($mqtt_devices as $dev) {
    $devices[$dev['DEV_ID']] = $dev['ID'];
}
$mqtt_devices = array_column($mqtt_devices, 'DEV_ID');

$link_id = uniqid();
$key = '';
$expire_time = 0;
$latest_db_check = time();
$latest_needs_check = time();
$latest_connect_attempt = 0;
$connect_failures = 0;
$client = null;

while (1) {
    $heartbeat();

    if (!$client || !$client->isConnected()) {
        if ($client) {
            try {
                $client->close();
            } catch (Throwable $e) {
                // Сокет уже закрыт — это нормальный путь переподключения.
            }
            $client = null;
        }

        // Не чаще одной попытки в 10 секунд, иначе сеть и API забиваются
        // запросами, а журнал — одинаковыми ошибками.
        if ((time() - $latest_connect_attempt) < 10) {
            sleep(1);
            continue;
        }
        $latest_connect_attempt = time();
        $heartbeat();

        $client = getMQTTConfig($link_id);
        if ($client === null) {
            $connect_failures++;
            if ($connect_failures === 1 || ($connect_failures % 10) === 0) {
                DebMes("MQTT-цикл Tuya: не удалось подключиться (попытка {$connect_failures}). Проверьте доступ к IoT-платформе.", 'tuya', 'error');
            }
            // Ждём с heartbeat, чтобы watchdog не принял паузу за зависание.
            for ($i = 0; $i < 30; $i++) {
                $heartbeat();
                sleep(1);
            }
            continue;
        }
        $connect_failures = 0;
        echo date('H:i:s') . ' MQTT подключён' . PHP_EOL;
    }

    $client->eventLoop();

    // Сессия MQTT живёт ограниченное время: переподключаемся заранее.
    if ($expire_time > 0 && time() >= ($expire_time - 60)) {
        $client->close();
        $client = null;
        $expire_time = 0;
        continue;
    }

    if ((time() - $latest_db_check) >= 2 * 60) {
        $mqtt_devices = (array)SQLSelect("SELECT ID, DEV_ID FROM tudevices WHERE STATUS=2;");
        $devices = array();
        foreach ($mqtt_devices as $dev) {
            $devices[$dev['DEV_ID']] = $dev['ID'];
        }
        $mqtt_devices = array_column($mqtt_devices, 'DEV_ID');
        $latest_db_check = time();
    }

    // Настройки могли измениться без перезапуска цикла: раз в минуту
    // проверяем, нужен ли ещё режим IoT, и сами отключаемся.
    if ((time() - $latest_needs_check) >= 60) {
        $latest_needs_check = time();
        $tuya_module->getConfig();
        if (!$tuya_module->cycleIsNeeded($cycle_name)) {
            echo date('H:i:s') . ' Цикл отключён: IoT-платформа больше не используется.' . PHP_EOL;
            $tuya_module->disablePluginCycle($cycle_name, 'IoT-платформа или устройства MQTT больше не настроены');
            break;
        }
    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        echo date('H:i:s') . ' Остановка по команде REBOOT или ONETIME: ' . basename(__FILE__) . PHP_EOL;
        break;
    }

    usleep(200000);
}

if ($client) {
    $client->close();
}

return;

/**
 * Получает конфигурацию MQTT и подключается к облаку Tuya.
 * Возвращает null, если подключиться не удалось: цикл повторит попытку сам,
 * вместо того чтобы завершаться и провоцировать перезапуск супервизором.
 */
function getMQTTConfig($link_id)
{
    global $tuya_module;
    global $key;
    global $expire_time;

    $tuya_module->getConfig();
    // Раньше здесь читался UID веб-аккаунта: при одной только IoT-настройке
    // он пустой, и запрос конфигурации MQTT всегда падал.
    $uid = !empty($tuya_module->config['TUYA_IOT_UID'])
        ? $tuya_module->config['TUYA_IOT_UID']
        : (string)($tuya_module->config['TUYA_UID'] ?? '');

    $data = array(
        'uid' => $uid,
        'link_id' => $link_id,
        'link_type' => 'mqtt',
        'topics' => 'device'
    );

    $url = '/v1.0/open-hub/access/config';
    $r_c = $tuya_module->Tuya_IOT_POST($url, $data, false);

    if (!$r_c || empty($r_c->success) || empty($r_c->result->client_id)) {
        $errorMsg = ($r_c && isset($r_c->msg)) ? $r_c->msg : 'неизвестная ошибка';
        DebMes("Не удалось получить конфигурацию MQTT для IoT: {$errorMsg}. UID: {$uid}. Проверьте права доступа проекта на developer.tuya.com.", 'tuya');
        echo date('H:i:s') . " Ошибка MQTT-конфигурации: {$errorMsg}" . PHP_EOL;
        return null;
    }

    $client_name = $r_c->result->client_id;
    $mqtt_url = parse_url((string)$r_c->result->url);
    if (empty($mqtt_url['host']) || empty($mqtt_url['port'])) {
        DebMes('MQTT-цикл Tuya: платформа вернула некорректный адрес брокера.', 'tuya', 'error');
        return null;
    }
    if (empty($r_c->result->source_topic->device)) {
        DebMes('MQTT-цикл Tuya: платформа не вернула топик устройств.', 'tuya', 'error');
        return null;
    }

    $expire_time = time() + (int)$r_c->result->expire_time;

    $key = substr((string)$r_c->result->password, 8, 24);

    try {
        $client = new LibMQTT\Client($mqtt_url['host'], (int)$mqtt_url['port'], $client_name);
        $client->setVerbose(0);
        $client->setCryptoProtocol('tls');
        $client->setAuthDetails($r_c->result->username, $r_c->result->password);
        if (!$client->connect()) {
            DebMes('MQTT-цикл Tuya: не удалось открыть TLS-соединение с брокером.', 'tuya', 'error');
            return null;
        }
        if (!$client->subscribe(array($r_c->result->source_topic->device => array('qos' => 1, 'function' => 'procMsg')))) {
            DebMes('MQTT-цикл Tuya: брокер не подтвердил подписку на топик устройств.', 'tuya', 'error');
            $client->close();
            return null;
        }
    } catch (Throwable $e) {
        DebMes('MQTT-цикл Tuya: ошибка подключения — ' . $e->getMessage(), 'tuya', 'error');
        return null;
    }

    return $client;
}

/**
 * Обработка сообщения MQTT: расшифровка состояния устройства и запись в
 * привязанные свойства. Любое чужое или битое сообщение просто игнорируется.
 */
function procMsg($msg_topic, $msg, $qos)
{
    global $key;
    global $mqtt_devices;
    global $tuya_module;
    global $cycle_debug;
    global $devices;

    $msg_json = json_decode((string)$msg, true);
    if (!is_array($msg_json) || empty($msg_json['data']) || !is_string($msg_json['data'])) {
        return;
    }

    $plain = openssl_decrypt(base64_decode($msg_json['data']), 'AES-128-ECB', (string)$key, OPENSSL_RAW_DATA);
    if ($plain === false) {
        return;
    }

    $result = json_decode($plain, true);
    if (!is_array($result) || empty($result['devId']) || !isset($result['status']) || !is_array($result['status'])) {
        return;
    }

    if (!in_array($result['devId'], (array)$mqtt_devices, true) || !isset($devices[$result['devId']])) {
        return;
    }

    foreach ($result['status'] as $status) {
        if (!is_array($status)) {
            continue;
        }
        foreach ($status as $k => $v) {
            if (!is_int($k)) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 1 : 0;
            }
            if ($cycle_debug) {
                DebMes($result['devId'] . '-' . $k . '=' . $v, 'tuya');
            }
            $tuya_module->processCommand($devices[$result['devId']], $k, $v, 0, false);
        }
    }
}
