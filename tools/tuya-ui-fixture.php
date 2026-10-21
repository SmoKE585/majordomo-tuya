<?php
/** Isolated UI fixtures. No application bootstrap, configuration or real services. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_ENV', 'testing');
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$core = str_replace('\\', '/', realpath($argv[1] ?? (__DIR__ . '/../../___majordomo')) ?: '');
if (!is_file($core . '/lib/jTemplate.class.php')) { throw new RuntimeException('Pass the MajorDoMo core directory as the first argument.'); }
define('DIR_MODULES', __DIR__ . '/../modules/');
define('ROOTHTML', '/');
define('ROOT', __DIR__ . '/no-real-runtime/');
define('SETTINGS_SITE_LANGUAGE', 'test');
function startMeasure($name) {}
function endMeasure($name) {}
function setEvalCode($code = '') {}
function DBSafe($value) { return addslashes($value); }
function outHash($record, &$out) { $out = array_merge($out, $record); }
function addLinkedProperty(...$args) { throw new RuntimeException('No real subscriptions in UI fixtures.'); }
function removeLinkedProperty(...$args) { throw new RuntimeException('No real subscriptions in UI fixtures.'); }
function SQLUpdate($table, $record) {
    global $fixtureWrites;
    $fixtureWrites[] = ['table' => $table, 'record' => $record];
    return $record['ID'];
}
function SQLInsert(...$args) { throw new RuntimeException('No database writes in UI fixtures.'); }
function SQLExec(...$args) { throw new RuntimeException('No database writes in UI fixtures.'); }
function SQLSelect($query) {
    global $fixtureDevices, $fixtureProperties, $variant;
    if (strpos($query, 'FROM tucommands') !== false) {
        if (strpos($query, 'INNER JOIN') !== false) return $fixtureProperties;
        preg_match('/DEVICE_ID=(\d+)/', $query, $match);
        $commands = [['ID' => 1, 'TITLE' => 'online', 'VALUE' => ($match[1] ?? 0) % 2]];
        if (($match[1] ?? 0) == 1) {
            $commands[] = ['ID' => 2, 'TITLE' => 'power', 'ALIAS' => '', 'VALUE' => 1, 'VALUE_UNIT' => '', 'LINKED_OBJECT' => '', 'LINKED_PROPERTY' => '', 'LINKED_METHOD' => ''];
            $commands[] = ['ID' => 3, 'TITLE' => '101', 'ALIAS' => 'Температура', 'VALUE' => '<img src=x onerror=alert(1)>', 'VALUE_UNIT' => '°C', 'LINKED_OBJECT' => 'Room', 'LINKED_PROPERTY' => 'temp', 'LINKED_METHOD' => ''];
        }
        return $commands;
    }
    if (strpos($query, 'FROM tudevices') !== false) return $variant === 'empty' ? [] : $fixtureDevices;
    throw new RuntimeException('Unexpected fixture query: ' . $query);
}
function SQLSelectOne($query) {
    global $fixtureDevices;
    if (strpos($query, 'FROM tudevices WHERE ID=') !== false) return $fixtureDevices[0];
    throw new RuntimeException('Unexpected fixture query: ' . $query);
}
// Render the real shared linkedobject template with in-memory choices only.
class linkedobject {
    public $owner, $object_field, $property_field, $method_field, $width, $result;
    public function restoreParams() {}
    public function getParams() {}
    public function run() {
        global $core;
        $data = [
            'UNIQ' => $this->object_field, 'WIDTH' => '200px', 'BASE_URL' => '/panel/linkedobject.html',
            'OBJECT_FIELD' => $this->object_field, 'PROPERTY_FIELD' => $this->property_field,
            'METHOD_FIELD' => $this->method_field, 'OBJECT_LABEL' => 'Объект',
            'PROPERTY_LABEL' => 'Свойство', 'METHOD_LABEL' => 'Метод',
            'OBJECTS_JSON' => '[{"title":"Room","class_title":"Rooms"}]',
        ];
        $owner = '';
        $this->result = (new jTemplate($core . '/templates/linkedobject/linkedobject.html', $data, $owner))->result;
    }
}
require $core . '/lib/jTemplate.class.php';
// Load declarations only: constructors and application bootstrap never run.
class module {}
require_once __DIR__ . '/../lib/tuyaFunctions.php';
if (function_exists('TuyaScene') || function_exists('TuyaIR') || method_exists('tuya', 'getScenes') || method_exists('tuya', 'infoScene') || method_exists('tuya', 'refresh_tudevices')) {
    throw new RuntimeException('Obsolete scene/remote API must be removed.');
}
$variant = $argv[2] ?? 'devices';
$fixtureDevices = [];
for ($i = 1; $i <= 31; $i++) {
    $fixtureDevices[] = [
        'ID' => $i, 'TITLE' => $i === 1 ? 'Кухня & свет' : 'Устройство ' . $i,
        'DEV_ID' => 'fixture-' . $i, 'DEV_IP' => '192.0.2.' . $i, 'STATUS' => ($i % 3),
        'TYPE' => 'light', 'IR_FLAG' => 0, 'CONTROL' => 1, 'TUYA_VER' => '3.4',
        'LOCAL_KEY' => 'fixture-only', 'MAC' => '', 'SEND12' => 0, 'FLAGS12' => '', 'DSP_FILLED' => 0,
        'UPDATED' => '2026-10-21 12:00:00',
    ];
}
$fixtureProperties = [];
foreach (['Температура', 'Яркость'] as $i => $title) {
    $fixtureProperties[] = [
        'ID' => $i + 101, 'TITLE' => (string)($i + 1), 'ALIAS' => $title,
        'VALUE' => $i ? str_repeat('Очень длинное значение ', 30) : '<script>alert("fixture")</script>',
        'UPDATED' => '2026-10-21 12:00:00', 'LINKED_OBJECT' => '', 'LINKED_PROPERTY' => '',
        'LINKED_METHOD' => '', 'VALUE_SCALE' => 0,
    ];
}
$module = new class {
    public $owner, $mode = '', $tab = '';
    public function search(&$out) { require __DIR__ . '/../modules/tuya/tuya_search.inc.php'; }
    public function edit(&$out) { $id = 1; require __DIR__ . '/../modules/tuya/tuya_edit.inc.php'; }
};
$module->owner = (object)['name' => 'panel'];
$session = (object)['data' => []];
$save_qry = false;
$tab = '';
$data = ['ACTION' => 'admin', 'DATA_SOURCE' => 'tudevices', 'VIEW_MODE' => '', 'TAB' => '',
    'CYCLERUN' => 1, 'LOCAL_CYCLERUN' => 1, 'TUYA_IOT' => 1, 'IOT_CYCLERUN' => 0,
    'TUYA_USERNAME' => 'fixture@example.test', 'TUYA_PASSWD' => 'fixture-only', 'TUYA_CLIENT_SECRET' => 'fixture-secret',
    'TUYA_CCODE' => 7, 'TUYA_BZTYPE' => 'smart_life', 'TUYA_LOCAL_INTERVAL' => 5,
    'TUYA_WEB_INTERVAL' => 30, 'TUYA_INTERVAL' => 30, 'TUYA_WEB' => 1,
    'TUYA_WEB_ENDPOINT' => 'https://fixture.invalid/api', 'TUYA_SID' => 'fixture-session',
];
if (in_array($variant, ['devices', 'empty'], true)) {
    $module->search($data);
} elseif (in_array($variant, ['edit', 'properties', 'new', 'error', 'saved'], true)) {
    $data['VIEW_MODE'] = 'edit_tudevices';
    if (in_array($variant, ['properties', 'saved'], true)) $module->tab = $data['TAB'] = 'data';
    if ($variant === 'saved') {
        $module->mode = 'update';
        $fixtureWrites = [];
        $linked_object101 = $linked_property101 = $linked_method101 = '';
        $alias101 = 'Сохранённое свойство';
    }
    $module->edit($data);
    if ($variant === 'saved') {
        if (count($fixtureWrites) !== 3) throw new RuntimeException('Expected one device and two property updates.');
        foreach (array_slice($fixtureWrites, 1) as $write) {
            if (isset($write['record']['VALUE']) || isset($write['record']['UPDATED'])) throw new RuntimeException('Joined display fields must not be saved into tucommands.');
            if ($write['record']['COLOR_CONVERT'] !== 0) throw new RuntimeException('Unchecked options must be zero.');
        }
    }
    if ($variant === 'new') { $data['ID'] = ''; $data['TITLE'] = ''; }
    if ($variant === 'error') { $data['ERR'] = 1; $data['ERR_TITLE'] = 1; $data['TITLE'] = ''; }
} else { throw new RuntimeException('Unknown fixture: ' . $variant); }
$translations = ['LANG_DATA_SAVED' => 'Данные сохранены', 'LANG_FILLOUT_REQURED' => 'Заполните обязательные поля'];
foreach ($translations as $key => $value) define($key, $value);
$owner = '';
$html = (new jTemplate(str_replace('\\', '/', __DIR__) . '/../templates/tuya/action_admin.html', $data, $owner))->result;
if (strpos($html, '[#') !== false || strpos($html, '<#') !== false) throw new RuntimeException('Unresolved template directive.');
echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/admin/admin.css"><script src="/assets/admin/admin.js" defer></script><title>Tuya fixture</title></head><body class="md-admin-shell"><main class="p-3">' . $html . '</main></body></html>';
