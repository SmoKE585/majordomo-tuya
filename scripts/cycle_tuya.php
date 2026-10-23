<?php
/**
 * Цикл облака Tuya: HA-обнаружение и родное облако.
 *
 * 2026-10-23 — heartbeat отмечается до и после блокирующих сетевых вызовов, а
 * сами вызовы ограничены таймаутами. Раньше первый запрос к недоступному
 * облаку мог занять больше CYCLE_HEARTBEAT_TIMEOUT, и супервизор считал цикл
 * зависшим (`cycle_hang`), хотя процесс ждал сеть. Если ни один облачный режим
 * не включён, цикл сам переводит себя в состояние `disabled`.
 */

chdir(dirname(__FILE__) . '/../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

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

// Первый heartbeat до любой сети: watchdog ждёт его после старта процесса.
$heartbeat();

echo date('H:i:s') . ' Запуск ' . basename(__FILE__) . PHP_EOL;

if (!$tuya_module->cycleIsNeeded($cycle_name)) {
    echo date('H:i:s') . ' Цикл отключён: облачные режимы не включены.' . PHP_EOL;
    $tuya_module->disablePluginCycle($cycle_name, 'облако Tuya и HA-облако не включены в настройках модуля');
    $db->Disconnect();
    exit(0);
}

$tuya_interval = 30;
$tuya_web_interval = 30;

if (!empty($tuya_module->config['TUYA_INTERVAL'])) {
    $tuya_interval = max(30, (int)$tuya_module->config['TUYA_INTERVAL']);
}

if (!empty($tuya_module->config['TUYA_WEB_INTERVAL'])) {
    $tuya_web_interval = max(10, (int)$tuya_module->config['TUYA_WEB_INTERVAL']);
}

$tuya_web = !empty($tuya_module->config['TUYA_WEB']);
$tuya_ha = !empty($tuya_module->config['TUYA_HA']);

echo date('H:i:s') . ' Инициализация Tuya' . PHP_EOL;
echo date('H:i:s') . " Период опроса — {$tuya_interval} сек." . PHP_EOL;

$latest_check = 0;
$latest_check_web = 0;
$latest_discovery = 0;
$latest_needs_check = 0;

if ($tuya_web) {
    $latest_check_web = time();
    $latest_discovery = time();
    $heartbeat();
    try {
        $tuya_module->Tuya_Web_Discovery_Devices();
    } catch (Throwable $e) {
        DebMes('Tuya cloud: ошибка начального обнаружения — ' . $e->getMessage(), 'cycle_tuya', 'error');
        echo date('H:i:s') . ' Ошибка обнаружения: ' . $e->getMessage() . PHP_EOL;
    }
}

while (true) {
    // Цикл жив даже после долгого запроса: следующий heartbeat каждую секунду.
    $heartbeat();

    // Настройки могли измениться без перезапуска: раз в минуту проверяем,
    // нужен ли ещё облачный режим, и сами отключаемся.
    if ((time() - $latest_needs_check) >= 60) {
        $latest_needs_check = time();
        $tuya_module->getConfig();
        if (!$tuya_module->cycleIsNeeded($cycle_name)) {
            echo date('H:i:s') . ' Цикл отключён: облачные режимы выключены.' . PHP_EOL;
            $tuya_module->disablePluginCycle($cycle_name, 'облако Tuya и HA-облако выключены в настройках модуля');
            $db->Disconnect();
            exit(0);
        }
    }

    if ($tuya_ha && (time() - $latest_check) >= $tuya_interval) {
        $latest_check = time();

        if (!empty($tuya_module->config['TUYA_REFRESH_TOKEN'])) {
            $heartbeat();
            try {
                $token = $tuya_module->RefreshToken();
                $tuya_module->Tuya_Discovery_Devices($token);
            } catch (Throwable $e) {
                DebMes('Tuya HA: ' . $e->getMessage(), 'cycle_tuya', 'error');
                echo date('H:i:s') . ' Ошибка HA-облака: ' . $e->getMessage() . PHP_EOL;
            }
        }
    }

    if ($tuya_web && (time() - $latest_check_web) >= $tuya_web_interval) {
        $latest_check_web = time();
        $heartbeat();

        try {
            if ((time() - $latest_discovery) >= 5 * 60) {
                $latest_discovery = time();
                $tuya_module->Tuya_Web_Discovery_Devices();
            } else {
                $tuya_module->Tuya_Web_Status();
            }
        } catch (Throwable $e) {
            DebMes('Tuya cloud: ' . $e->getMessage(), 'cycle_tuya', 'error');
            echo date('H:i:s') . ' Ошибка облака: ' . $e->getMessage() . PHP_EOL;
        }
    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        $db->Disconnect();
        echo date('H:i:s') . ' Остановка по команде REBOOT или ONETIME: ' . basename(__FILE__) . PHP_EOL;
        exit(0);
    }

    sleep(1);
}
