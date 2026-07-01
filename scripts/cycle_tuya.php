<?php
/**
 * Цикл опроса Tuya (HA облако + оригинальное облако)
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

$tuya_module = new tuya();
$tuya_module->getConfig();

echo date('H:i:s') . ' Запуск ' . basename(__FILE__) . PHP_EOL;

$latest_check = 0;
$latest_check_web = 0;
$latest_discovery = 0;

$latest_disc = 0;

$cycle_debug = false;

$tuya_interval = 30;
$tuya_web_interval = 30;
$tuya_web = false;
$latest_cycle_check = 0;


if (!empty($tuya_module->config['TUYA_INTERVAL'])) {
    $tuya_interval = (int)$tuya_module->config['TUYA_INTERVAL'];
}

if (!empty($tuya_module->config['TUYA_WEB_INTERVAL'])) {
    $tuya_web_interval = (int)$tuya_module->config['TUYA_WEB_INTERVAL'];
}

$tuya_web = !empty($tuya_module->config['TUYA_WEB']);
$tuya_ha  = !empty($tuya_module->config['TUYA_HA']);


echo date('H:i:s') . ' Инициализация Tuya' . PHP_EOL;
echo date('H:i:s') . " Период опроса — $tuya_interval сек." . PHP_EOL;

if ($tuya_web) {
    $latest_check_web = time();
    $tuya_module->Tuya_Web_Discovery_Devices();
}



while (1) {
    if ((time() - $latest_cycle_check) >= 20) {
        $latest_cycle_check = time();
        setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
    }

    if ($tuya_ha && (time() - $latest_check) >= $tuya_interval) {
        $latest_check = time();

        if (!empty($tuya_module->config['TUYA_REFRESH_TOKEN'])) {
            $token = $tuya_module->RefreshToken();
            $tuya_module->Tuya_Discovery_Devices($token);
        }

    }

    if ((time() - $latest_check_web) >= $tuya_web_interval && $tuya_web) {
        $latest_check_web = time();

        if ((time() - $latest_discovery) >= 5 * 60) {
            $latest_discovery = time();
            $tuya_module->Tuya_Web_Discovery_Devices();
        } else {
            $tuya_module->Tuya_Web_Status();
        }

    }

    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        $db->Disconnect();
        echo date('H:i:s') . ' Остановка по команде REBOOT или ONETIME: ' . basename(__FILE__) . PHP_EOL;
        exit;
    }

    sleep(1);
}

echo date('H:i:s') . ' Неожиданное завершение цикла' . PHP_EOL;

DebMes('Неожиданное завершение цикла: ' . basename(__FILE__));
