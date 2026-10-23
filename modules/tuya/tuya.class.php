<?php
/**
 * Tuya — устройства Tuya и Smart Life.
 *
 * Модуль остаётся отдельным: облачный опрос, локальный протокол 3.1–3.5 и
 * IoT MQTT живут в modules/tuya, фоновые задачи — в scripts/cycle_tuya*.php.
 * Настройки хранятся в конфигурации модуля, а общие сервисы ядра используются
 * только для циклов, сессии и привязок свойств.
 *
 * Изменения:
 * 2026-10-23 — приведён к текущей структуре ядра: название «Tuya», убраны
 *   define(), параметры читаются через gr() с приведением типов, действия
 *   требуют POST и CSRF-токен, циклы управляются CycleManager и сами
 *   отключаются, когда режим не настроен; сетевые вызовы получили таймауты и
 *   heartbeat для watchdog; удалён мёртвый код (сцены, пульты, tucommands,
 *   $session->data).
 *
 * @package project
 * @author <fandaymon@gmail.com>
 * @copyright 2019-2026 (c)
 */

class tuya extends module
{
    /**
     * Имя цикла, которому класс сообщает heartbeat во время блокирующих
     * сетевых вызовов. Пустая строка — класс работает из веб-запроса или
     * очереди, и трогать heartbeat чужого цикла нельзя.
     */
    public string $cycleName = '';

    /** Таймауты сети (сек): цикл не должен молчать дольше CYCLE_HEARTBEAT_TIMEOUT. */
    private const HTTP_CONNECT_TIMEOUT = 5;
    private const HTTP_TIMEOUT = 15;

    /**
     * tuya
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = 'tuya';
        $this->title = 'Tuya';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        return parent::saveParams(array(
            'id' => (int)($this->id ?? 0),
            'view_mode' => (string)($this->view_mode ?? ''),
            'edit_mode' => (string)($this->edit_mode ?? ''),
            'data_source' => (string)($this->data_source ?? ''),
            'tab' => (string)($this->tab ?? ''),
        ));
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        $id = gr('id', 'int');
        if ($id > 0) {
            $this->id = $id;
        }

        $mode = gr('mode');
        if (is_scalar($mode) && $mode !== '') {
            $this->mode = (string)$mode;
        }

        $viewMode = gr('view_mode');
        if (is_scalar($viewMode)) {
            $this->view_mode = (string)$viewMode;
        }

        $editMode = gr('edit_mode');
        if (is_scalar($editMode)) {
            $this->edit_mode = (string)$editMode;
        }

        $dataSource = gr('data_source');
        if (is_scalar($dataSource)) {
            $this->data_source = (string)$dataSource;
        }

        $tab = gr('tab');
        if (is_scalar($tab)) {
            $this->tab = (string)$tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        // Модульный AJAX приходит с action=admin (так его вызывает ядро),
        // поэтому поиск устройств обрабатывается до отрисовки страницы.
        if (!empty($this->ajax) && gr('op') === 'scan') {
            $this->scanDevices();
            exit;
        }

        $out = array();

        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }

        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }

        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['DATA_SOURCE'] = $this->data_source;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * Страница модуля доступна только после POST: настройки и привязки меняют
     * данные, а GET-запрос не должен их трогать (F5 и префетч не повторяют запись).
     */
    private function isPost(): bool
    {
        return isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST';
    }

    /**
     * Токен CSRF хранится в сессии ядра; шаблоны выводят его в скрытом поле.
     */
    public function csrfToken(): string
    {
        $session = \MajorDoMo\Core\Core::session();
        if (!$session->has('tuya_csrf')) {
            $session->set('tuya_csrf', bin2hex(random_bytes(32)));
        }
        return (string)$session->get('tuya_csrf');
    }

    /**
     * Проверка токена для POST-форм. Для ссылок (удаление) токен приходит в
     * query-параметре — чужой сайт его не знает.
     */
    public function checkCsrf(): bool
    {
        $token = gr('csrf_token');
        $token = is_scalar($token) ? (string)$token : '';
        $expected = (string)\MajorDoMo\Core\Core::session()->get('tuya_csrf');
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Сетевой вызов может занять десятки секунд: сообщаем watchdog-у цикла, что
     * процесс жив, иначе он решит, что цикл завис.
     */
    private function touchCycleHeartbeat(): void
    {
        if ($this->cycleName !== '') {
            setGlobal($this->cycleName . 'Run', time(), 1);
        }
    }

    /**
     * Сколько устройств использует каждый источник статуса (0 — облако,
     * 1 — локально, 2 — MQTT). null — запрос не удался: состояние неизвестно,
     * и циклы лучше не трогать, чем отключить их из-за сбоя БД.
     *
     * @return array<int,int>|null
     */
    private function deviceStatusCounts(): ?array
    {
        $rows = SQLSelect('SELECT STATUS, COUNT(*) AS CNT FROM tudevices GROUP BY STATUS');
        if (!is_array($rows)) {
            return null;
        }
        $counts = array();
        foreach ($rows as $row) {
            $counts[(int)$row['STATUS']] = (int)$row['CNT'];
        }
        return $counts;
    }

    /**
     * Какие циклы модулю реально нужны при текущих настройках и устройствах.
     *
     * @return array<string,bool> имя цикла => нужен ли он
     */
    public function cycleRequirements(): array
    {
        $this->getConfig();
        $counts = $this->deviceStatusCounts();
        $localRequired = $counts === null ? true : (($counts[1] ?? 0) > 0);
        $iotRequired = $counts === null ? true : (($counts[2] ?? 0) > 0);

        return array(
            // Облачный цикл: обнаружение в HA-облаке и опрос родного облака Tuya.
            'cycle_tuya' => !empty($this->config['TUYA_WEB']) || !empty($this->config['TUYA_HA']),
            // Локальный опрос нужен, только если устройство получает статус локально.
            'cycle_local_tuya' => $localRequired,
            // IoT MQTT — только с ключами платформы и устройством на этом источнике.
            'cycle_tuya_iot' => $iotRequired
                && !empty($this->config['TUYA_IOT'])
                && trim((string)($this->config['TUYA_CLIENT_ID'] ?? '')) !== ''
                && trim((string)($this->config['TUYA_CLIENT_SECRET'] ?? '')) !== '',
        );
    }

    public function cycleIsNeeded(string $cycle): bool
    {
        $requirements = $this->cycleRequirements();
        return !empty($requirements[$cycle]);
    }

    /**
     * Держит состояние циклов в соответствии с настройками: неиспользуемый
     * режим отключается, включённый — запускается; при сохранении настроек
     * работающие циклы перезапускаются, чтобы прочитать новую конфигурацию.
     */
    public function reconcileCycles(bool $restartRunning = false): void
    {
        try {
            $manager = new \MajorDoMo\Core\Cycles\CycleManager(ROOT);
            foreach ($this->cycleRequirements() as $cycle => $needed) {
                $state = $manager->state($cycle);
                if ($needed) {
                    if ($state['desired'] === \MajorDoMo\Core\Cycles\CycleStateStore::DISABLED) {
                        $manager->enable($cycle);
                    } elseif ($restartRunning && $state['desired'] === \MajorDoMo\Core\Cycles\CycleStateStore::RUNNING) {
                        $manager->restart($cycle);
                    }
                } elseif ($state['desired'] !== \MajorDoMo\Core\Cycles\CycleStateStore::DISABLED) {
                    $manager->disable($cycle);
                }
            }
        } catch (\Throwable $e) {
            DebMes('Tuya: не удалось согласовать состояние циклов — ' . $e->getMessage(), 'tuya', 'error');
        }
    }

    /**
     * Сам цикл отключается, когда его режим не настроен: супервизор не будет
     * поднимать процесс снова и не станет писать cycle_hang/cycle_stop.
     */
    public function disablePluginCycle(string $cycle, string $reason): void
    {
        try {
            (new \MajorDoMo\Core\Cycles\CycleManager(ROOT))->disable($cycle);
        } catch (\Throwable $e) {
            DebMes('Tuya: не удалось отключить ' . $cycle . ' — ' . $e->getMessage(), 'tuya', 'error');
        }
        DebMes('Цикл ' . $cycle . ' отключён: ' . $reason, 'tuya');
    }

    /** Человеческая подпись состояния цикла для шапки страницы. */
    private function cycleLabel(string $cycle): string
    {
        try {
            $state = (new \MajorDoMo\Core\Cycles\CycleManager(ROOT))->state($cycle);
        } catch (\Throwable $e) {
            return 'неизвестно';
        }
        if ($state['status'] === 'disabled' || $state['desired'] === 'disabled') {
            return 'отключён';
        }
        return $state['online'] ? 'работает' : 'остановлен';
    }

    function admin(&$out)
    {
        $this->getConfig();

        $out['CYCLERUN'] = $this->cycleLabel('cycle_tuya');
        $out['LOCAL_CYCLERUN'] = $this->cycleLabel('cycle_local_tuya');
        $out['IOT_CYCLERUN'] = $this->cycleLabel('cycle_tuya_iot');
        $out['CSRF_TOKEN'] = $this->csrfToken();


        $out['TUYA_USERNAME'] = htmlspecialchars((string)($this->config['TUYA_USERNAME'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_PASSWD'] = htmlspecialchars((string)($this->config['TUYA_PASSWD'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_INTERVAL'] = htmlspecialchars((string)($this->config['TUYA_INTERVAL'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_LOCAL_INTERVAL'] = htmlspecialchars((string)($this->config['TUYA_LOCAL_INTERVAL'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_LOCAL_IP'] = htmlspecialchars((string)($this->config['TUYA_LOCAL_IP'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_BZTYPE'] = htmlspecialchars((string)($this->config['TUYA_BZTYPE'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_CCODE'] = htmlspecialchars((string)($this->config['TUYA_CCODE'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_SID'] = htmlspecialchars((string)($this->config['TUYA_SID'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_WEB'] = htmlspecialchars((string)($this->config['TUYA_WEB'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_WEB_INTERVAL'] = htmlspecialchars((string)($this->config['TUYA_WEB_INTERVAL'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_WEB_ENDPOINT'] = htmlspecialchars((string)($this->config['TUYA_WEB_ENDPOINT'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_CYCLE_DEBUG'] = htmlspecialchars((string)($this->config['TUYA_CYCLE_DEBUG'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_HA'] = htmlspecialchars((string)($this->config['TUYA_HA'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_IOT'] = htmlspecialchars((string)($this->config['TUYA_IOT'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_CLIENT_ID'] = htmlspecialchars((string)($this->config['TUYA_CLIENT_ID'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out['TUYA_CLIENT_SECRET'] = htmlspecialchars((string)($this->config['TUYA_CLIENT_SECRET'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->view_mode == 'update_settings') {
            // Сохранение настроек меняет данные: только POST и только свой токен.
            if (!$this->isPost() || !$this->checkCsrf()) {
                DebMes('Tuya: запрос сохранения настроек отклонён — нужен POST с CSRF-токеном.', 'tuya');
                $this->redirect('?');
            }

            $this->config['TUYA_USERNAME'] = gr('tuya_username');
            $this->config['TUYA_PASSWD'] = gr('tuya_passwd');
            $this->config['TUYA_HA'] = !empty(gr('tuya_ha'));

            $this->config['TUYA_INTERVAL'] = max(30, gr('tuya_interval', 'int'));
            $this->config['TUYA_LOCAL_INTERVAL'] = max(1, gr('tuya_local_interval', 'int'));
            $this->config['TUYA_LOCAL_IP'] = gr('tuya_local_ip');

            $bztype = (string)gr('tuya_bztype');
            if ($bztype !== 'smart_life') {
                $bztype = 'tuya';
            }
            if ($bztype !== (string)($this->config['TUYA_BZTYPE'] ?? '')) {
                // Смена приложения меняет домен и сессию — старый SID больше не годен.
                $this->config['TUYA_SID'] = '';
            }
            $this->config['TUYA_BZTYPE'] = $bztype;
            $this->config['TUYA_CCODE'] = gr('tuya_ccode');

            $this->config['TUYA_WEB'] = !empty(gr('tuya_web'));
            $this->config['TUYA_WEB_INTERVAL'] = max(10, gr('tuya_web_interval', 'int'));
            $this->config['TUYA_WEB_ENDPOINT'] = gr('tuya_web_endpoint');

            $this->config['TUYA_IOT'] = !empty(gr('tuya_iot'));
            $this->config['TUYA_CLIENT_ID'] = gr('tuya_client_id');
            $this->config['TUYA_CLIENT_SECRET'] = gr('tuya_client_secret');
            $this->config['TUYA_CYCLE_DEBUG'] = !empty(gr('tuya_cycle_debug'));

            if ($this->config['TUYA_HA']) {
                $token = json_decode((string)$this->getToken(
                    (string)$this->config['TUYA_USERNAME'],
                    (string)$this->config['TUYA_PASSWD'],
                    (string)$this->config['TUYA_BZTYPE'],
                    (string)$this->config['TUYA_CCODE']
                ));
                if (isset($token->responseStatus) && $token->responseStatus === 'error') {
                    DebMes('Tuya HA: не удалось получить токен — ' . ($token->responseMsg ?? 'неизвестная ошибка'), 'tuya', 'error');
                    registerError('tuya_ha_auth', 'Tuya: HA-облако отклонило вход. Проверьте логин, пароль и приложение.');
                } elseif (isset($token->access_token)) {
                    $this->config['TUYA_ACCESS_TOKEN'] = $token->access_token;
                    $this->config['TUYA_REFRESH_TOKEN'] = $token->refresh_token;
                    $this->config['TUYA_TIME'] = time() + (int)$token->expires_in;
                    $this->Tuya_Discovery_Devices($token->access_token);
                }
            }
            $this->saveConfig();

            if (!empty($this->config['TUYA_WEB'])) {
                if (empty($this->config['TUYA_WEB_ENDPOINT']) || $this->config['TUYA_WEB_ENDPOINT'] != 'https://a1.tuyaeu.com/api.json') {
                    $this->config['TUYA_WEB_ENDPOINT'] = 'https://a1.tuyaeu.com/api.json';
                    $this->saveConfig();
                }
                if (empty($this->config['TUYA_SID'])) {
                    $result = $this->Tuya_Web_Login();
                }
                $this->Tuya_Web_Discovery_Devices();
            }

            // Циклы читают настройки при старте: перезапускаем работающие и
            // отключаем те, чей режим больше не выбран.
            $this->reconcileCycles(true);

            $this->redirect('?');
        }
        if (isset($this->data_source) && !gr('data_source')) {
            $out['SET_DATASOURCE'] = 1;
        }

        if ($this->data_source == 'tudevices' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_tudevices') {

                $this->search_tudevices($out);
            }
            if ($this->view_mode == 'edit_tudevices') {
                $this->edit_tudevices($out, $this->id);
            }
            if ($this->view_mode == 'delete_tudevices') {
                $this->delete_tudevices($this->id);
                $this->redirect("?data_source=tudevices&tab=" . $this->tab);
            }
        }
    }

    /**
     * FrontEnd / AJAX
     *
     * Отдельной пользовательской страницы у модуля нет: AJAX-поиск устройств в
     * локальной сети и обычный просмотр используют один интерфейс.
     *
     * @access public
     */
    function usual(&$out)
    {
        if ($this->ajax) {
            if (gr('op') === 'scan') {
                $this->scanDevices();
                exit;
            }
        }
        $this->admin($out);
    }

    /**
     * tudevices search
     *
     * @access public
     */
    function search_tudevices(&$out)
    {
        require(DIR_MODULES . $this->name . '/tuya_search.inc.php');
    }

    /**
     * tudevices edit/add
     *
     * @access public
     */
    function edit_tudevices(&$out, $id)
    {
        // Сохранение карточки и удаление отдельного свойства меняют данные:
        // только POST для формы и CSRF-токен для ссылки удаления.
        if ($this->mode === 'update' && (!$this->isPost() || !$this->checkCsrf())) {
            DebMes('Tuya: сохранение устройства отклонено — нужен POST с CSRF-токеном.', 'tuya');
            $this->redirect('?data_source=tudevices');
        }
        if (gr('delete_id', 'int') > 0 && !$this->checkCsrf()) {
            DebMes('Tuya: удаление свойства отклонено — нет CSRF-токена.', 'tuya');
            $this->redirect('?data_source=tudevices');
        }
        require(DIR_MODULES . $this->name . '/tuya_edit.inc.php');
        if ($this->mode === 'update') {
            // Устройство могло сменить источник статуса — циклы должны это учесть.
            $this->reconcileCycles();
        }
    }

    /**
     * tudevices delete record
     *
     * @access public
     */
    function delete_tudevices($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return;
        }
        if (!$this->checkCsrf()) {
            DebMes('Tuya: удаление устройства отклонено — нет CSRF-токена.', 'tuya');
            $this->redirect('?data_source=tudevices');
        }
        $rec = SQLSelectOne("SELECT * FROM tudevices WHERE ID=" . $id);
        if (!$rec) {
            return;
        }

        // Сначала снимаем привязки: после удаления команд их уже не найти.
        $linked = SQLSelect("SELECT * FROM tucommands WHERE DEVICE_ID=" . $id . " AND LINKED_OBJECT!='' AND LINKED_PROPERTY!=''");
        foreach ((array)$linked as $command) {
            removeLinkedProperty($command['LINKED_OBJECT'], $command['LINKED_PROPERTY'], $this->name);
        }

        SQLExec("DELETE FROM tuvalues WHERE ID IN (SELECT ID FROM tucommands WHERE DEVICE_ID=" . $id . ")");
        SQLExec("DELETE FROM tucommands WHERE DEVICE_ID=" . $id);
        SQLExec("DELETE FROM tudevices WHERE ID=" . $id);

        $this->reconcileCycles();
    }



    function scanDevices()
    {
        if (!function_exists('socket_create')) {
            echo 'Расширение PHP sockets не установлено: поиск в локальной сети недоступен.';
            return;
        }

        $udp_key_v31 = hex2bin(md5('yGAdlopoPVldABfn'));
        $udp_key_v33_34 = hex2bin(md5('yGAdlopoPVldABfn')); // Для совместимости, может быть одинаковым
        $udp_key_v35 = md5('yGAdlopoPVldABfn', true); // Binary MD5 для AES ключа

        $devices = array();
        $found_devices = array(); // Для отслеживания уникальных ID устройств

        // Получаем локальный IP-адрес один раз
        $local_ip = $this->getLocalIPAddress();
        $broadcast_address = '255.255.255.255'; // Обычно так


        // Сокет для протокола 3.1 (порт 6666)
        $socket_v31 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket_v31, "0.0.0.0", 6666);
        socket_set_option($socket_v31, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 2, "usec" => 0));

        // Сокет для протоколов 3.3 и 3.4 (порт 6667)
        $socket_v33_34 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket_v33_34, "0.0.0.0", 6667);
        socket_set_option($socket_v33_34, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 2, "usec" => 0));

        // Сокет для протокола 3.5 (порт 7000)
        $socket_v35 = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket_v35, $local_ip !== '' ? $local_ip : '0.0.0.0', 7000);
        socket_set_option($socket_v35, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 2, "usec" => 0));
        socket_set_option($socket_v35, SOL_SOCKET, SO_BROADCAST, 1); // Включаем широковещание

        echo '<H4>В локальной сети найдены следующие устройства:</H2>';
        echo '<table>';

        $start_time = time();
        $scan_duration = 10; // Общая продолжительность сканирования в секундах
        $seq_v35 = 0;

        while ((time() - $start_time) < $scan_duration) {
            $read_sockets = array($socket_v31, $socket_v33_34, $socket_v35);
            $write = null;
            $except = null;
            $num_changed_sockets = socket_select($read_sockets, $write, $except, 0, 500000); // 0.5 секунды таймаут

            if ($num_changed_sockets > 0) {
                foreach ($read_sockets as $socket) {
                    $from = null;
                    $port = null;
                    $buf = '';
                    socket_recvfrom($socket, $buf, 2048, 0, $from, $port);

                    if ($buf) {
                        $prefix = bin2hex(substr($buf, 0, 4));

                        if ($prefix == '000055aa') {
                            // Обработка пакетов протоколов 3.3, 3.4
                            $data = substr($buf, 20, -8);
                            $udp_key = ($port == 6666) ? $udp_key_v31 : $udp_key_v33_34;
                            $result_json = openssl_decrypt($data, 'AES-128-ECB', $udp_key, OPENSSL_RAW_DATA);
                            $result = json_decode($result_json, true);
                            if ($result && isset($result['gwId']) && !in_array($result['gwId'], $found_devices)) {
                                $this->displayDeviceInfo($result, $from, ($port == 6666) ? 'v3.1' : 'v3.3/v3.4');
                                $found_devices[] = $result['gwId'];
                            }

                        } elseif ($prefix == '00006699') {
                            // Обработка пакетов протокола 3.5

                            if ($from != $local_ip) {
                                $packet = $buf;
                                $lenBytes = substr($packet, 14, 4);
                                $totalLen = unpack("N", $lenBytes)[1]; // big-endian

                                // Расчёт длины payload
                                $payloadLen = $totalLen - 12 - 16; // - IV - tag
                                if ($payloadLen <= 0) return null;

                                $aad = substr($packet, 4, 14);

                                $iv = substr($packet, 18, 12);
                                $payload = substr($packet, 30, $payloadLen);
                                $tag = substr($packet, 30 + $payloadLen, 16);

                                // Расшифровка
                                $key = md5("yGAdlopoPVldABfn", true);
                                $decrypted = openssl_decrypt($payload, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);

                                if ($decrypted !== false) {
                                    // Проверка на префикс кода возврата (4 байта)
                                    if (strlen($decrypted) > 4) {
                                        $return_code_hex = bin2hex(substr($decrypted, 0, 4));
                                        $v35_payload_json = substr($decrypted, 4);
                                        $v35_result = json_decode($v35_payload_json, true);
                                        if ($v35_result && isset($v35_result['gwId']) && !in_array($v35_result['gwId'], $found_devices)) {
                                            $this->displayDeviceInfo($v35_result, $from, 'v3.5');
                                            $found_devices[] = $v35_result['gwId'];
                                        }

                                    } else {
                                        $v35_result = json_decode($decrypted, true);
                                        if ($v35_result && isset($v35_result['gwId']) && !in_array($v35_result['gwId'], $found_devices)) {
                                            $this->displayDeviceInfo($v35_result, $from, 'v3.5');
                                            $found_devices[] = $v35_result['gwId'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Отправка широковещательного запроса для v3.5 периодически
            if ($local_ip) {

                $discovery_payload = json_encode(["from" => "app", "ip" => $local_ip]);
                $v35_packet = $this->encodeMessage35($discovery_payload, $udp_key_v35, 37, 0);
                socket_sendto($socket_v35, $v35_packet, strlen($v35_packet), 0, $broadcast_address, 7000);

            }
            usleep(250000); // 0.25 секунды задержка между широковещательными запросами
        }

        echo '</table>';
        socket_close($socket_v31);
        socket_close($socket_v33_34);
        socket_close($socket_v35);


    }

    function getLocalIPAddress()
    {
        $this->getConfig();
        if (!empty($this->config['TUYA_LOCAL_IP'])) {
            return $this->config['TUYA_LOCAL_IP'];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // hostname -I есть только в Linux-подобных системах.
            $ip = gethostbyname((string)gethostname());
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        } else {
            $ip = trim((string)shell_exec('hostname -I'));
            $ips = preg_split('/\s+/', $ip);
            if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP)) {
                return $ips[0];
            }
        }
        return (string)($_SERVER['SERVER_ADDR'] ?? '');
    }


    function displayDeviceInfo($result, $ip, $protocol)
    {

        $dev_id = (isset($result['devId'])) ? $result['devId'] : (isset($result['gwId']) ? $result['gwId'] : null);

        $protocol = (isset($result['version']) ? $result['version'] : '');

        if ($dev_id) {
            echo '<tr>';

            $rec = SQLSelectOne("SELECT * FROM tudevices WHERE DEV_ID='" . DBSafe($dev_id) . "'");
            if (isset($rec['ID']) && ($rec['DEV_IP'] != $ip || ($rec['TUYA_VER'] != $protocol))) {
                $rec['DEV_IP'] = $ip;
                $rec['TUYA_VER'] = $protocol;
                SQLUpdate('tudevices', $rec);
            } elseif (!isset($rec['ID'])) {
                $new_device = array(
                    'DEV_ID' => $dev_id,
                    'DEV_IP' => $ip,
                    'TUYA_VER' => $protocol,
                    'TITLE' => 'Новое устройство Tuya (' . $dev_id . ')'
                );
                SQLInsert('tudevices', $new_device);
            }

            echo '<td><b>' . (isset($rec['TITLE']) ? $rec['TITLE'] : '') . '</b></td>';
            echo '<td>' . $dev_id . '</td>';
            echo '<td> (' . $protocol . ') </td>';
            echo '<td>' . $ip . '</td>';
            echo '</tr>';
        }
    }

    function getToken($username, $passwd, $bztype, $ccode)
    {
        $this->touchCycleHeartbeat();
        $sURL = 'https://px1.tuyaeu.com/homeassistant/auth.do';
        $sPD = "userName=" . $username . "&password=" . $passwd . "&countryCode=" . $ccode . "&bizType=" . $bztype . "&from=tuya";
        $aHTTP = array(
            'http' =>
                array(
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $sPD,
                    'timeout' => self::HTTP_TIMEOUT,
                )
        );
        $context = stream_context_create($aHTTP);
        $contents = @file_get_contents($sURL, false, $context);
        if ($contents === false) {
            DebMes('Tuya HA: нет ответа от px1.tuyaeu.com (таймаут ' . self::HTTP_TIMEOUT . ' с).', 'tuya', 'error');
            return '';
        }

        return $contents;
    }

    function RefreshToken()
    {
        $this->getConfig();

        if (time() > (int)($this->config['TUYA_TIME'] ?? 0)) {
            $this->touchCycleHeartbeat();
            $sURL = 'https://px1.tuyaeu.com/';
            $sPD = "grant_type=refresh_token&refresh_token=" . $this->config['TUYA_REFRESH_TOKEN'];


            $aHTTP = array(
                'http' =>
                    array(
                        'method' => 'GET',
                        'header' => 'Content-type: application/x-www-form-urlencoded',
                        'content' => $sPD,
                        'timeout' => self::HTTP_TIMEOUT,
                    )
            );
            $context = stream_context_create($aHTTP);
            $contents = @file_get_contents($sURL, false, $context);

            $token = json_decode((string)$contents);

            if (isset($token->access_token) && $token->access_token) {
                $this->config['TUYA_ACCESS_TOKEN'] = $token->access_token;
                $this->config['TUYA_REFRESH_TOKEN'] = $token->refresh_token;
                $this->config['TUYA_TIME'] = time() + $token->expires_in;

                $this->saveConfig();
            } else {
                $token = json_decode($this->getToken(
                    (string)$this->config['TUYA_USERNAME'],
                    (string)$this->config['TUYA_PASSWD'],
                    (string)$this->config['TUYA_BZTYPE'],
                    (string)$this->config['TUYA_CCODE']
                ));
                if (isset($token->responseStatus) && $token->responseStatus === 'error') {
                    DebMes('Не удалось получить токен HA. Пользователь: ' . $this->config['TUYA_USERNAME']
                        . ', приложение: ' . $this->config['TUYA_BZTYPE'] . ', ошибка: ' . ($token->responseMsg ?? 'без описания'), 'tuya', 'error');
                }
                // Пустой ответ сети не должен превращаться в фатальную ошибку цикла.
                if (isset($token->access_token)) {
                    $this->config['TUYA_ACCESS_TOKEN'] = $token->access_token;
                    $this->config['TUYA_REFRESH_TOKEN'] = $token->refresh_token;
                    $this->config['TUYA_TIME'] = time() + (int)$token->expires_in;
                    $this->Tuya_Discovery_Devices($token->access_token);

                    $this->saveConfig();
                }
            }
        }

        return $this->config['TUYA_ACCESS_TOKEN'] ?? '';
    }

    function TuyaLocalEncrypt($command, $json, $local_key, $tuya_ver = '3.3')
    {
        $prefix = "000055aa00000000000000";
        $suffix = "000000000000aa55";
        if ($tuya_ver == '3.1') {
            $json_payload = $json;
        } else {
            $json_payload = openssl_encrypt($json, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
        }
        if ($command != "0a" && $command != "12" && $tuya_ver != "3.1") {
            $json_payload = hex2bin("332E33000000000000000000000000" . bin2hex($json_payload));
        }


        $postfix_payload = hex2bin(bin2hex($json_payload) . $suffix);
        $postfix_payload_hex_len = dechex(strlen($postfix_payload));

        if (strlen($postfix_payload_hex_len) > 2) {
            $buffer = hex2bin($prefix . $command . '00000' . $postfix_payload_hex_len) . $postfix_payload;

        } else {
            $buffer = hex2bin($prefix . $command . '000000' . $postfix_payload_hex_len) . $postfix_payload;
        }

        $buffer = bin2hex($buffer);
        $buffer1 = strtoupper(substr($buffer, 0, -16));

        $hex_crc = dechex(crc32(hex2bin($buffer1)));
        $hex_crc = str_pad($hex_crc, 8, "0", STR_PAD_LEFT);
        $buffer = substr($buffer, 0, -16) . ($hex_crc) . substr($buffer, -8);
        return hex2bin($buffer);
    }


    function TuyaLocalMsg($command, $dev_id, $local_key, $local_ip, $data = '', $cid = '', $tuya_ver = '3.3')
    {
        $this->touchCycleHeartbeat();

        if ($tuya_ver == '3.4') {

            return $this->TuyaLocalMsg34($command, $dev_id, $local_key, $local_ip, $data, $cid);
        } else if ($tuya_ver == '3.5') {
            return $this->tuyaLocalMsg35($command, $dev_id, $local_key, $local_ip, $data, $cid);
        }

        $prefix = "000055aa00000000000000";
        $suffix = "000000000000aa55";

        if ($tuya_ver == '3.1') {
            $gw_name = 'uid';
        } else {
            $gw_name = 'gwId';
        }

        if ($command == 'STATUS') {
            $hexByte = "0a";
            $json = '{"' . $gw_name . '":"' . $dev_id . '","devId":"' . $dev_id . '"}';

        } else {
            $hexByte = "07";
            if ($cid == '') {
                $dps = $data;
                $json = '{"' . $gw_name . '":"' . $dev_id . '","devId":"' . $dev_id . '", "t": "' . time() . '", "dps": ' . $dps . '}';
            } else {
                $json = '{"dps":' . $data . ',"cid":"' . $cid . '","t":' . time() . '}';
            }
        }

        if ($tuya_ver == '3.1') {
            if ($command != 'STATUS') {
                $json_payload = base64_encode(openssl_encrypt($json, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA));

                $preMd5String = 'data=' . $json_payload . '||lpv=' . hex2bin("332E31") . '||' . $local_key;

                $hexdigest = md5($preMd5String);
                $json_payload = hex2bin("332E31") . substr($hexdigest, 8, 16) . $json_payload;
            } else {
                $json_payload = $json;
            }
        } else {
            $json_payload = openssl_encrypt($json, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
            if ($command != 'STATUS') {
                $json_payload = hex2bin("332E33000000000000000000000000" . bin2hex($json_payload));
            }

        }


        $postfix_payload = hex2bin(bin2hex($json_payload) . $suffix);
        $postfix_payload_hex_len = dechex(strlen($postfix_payload));


        if (strlen($postfix_payload_hex_len) > 2) {
            $buffer = hex2bin($prefix . $hexByte . '00000' . $postfix_payload_hex_len) . $postfix_payload;

        } else {
            $buffer = hex2bin($prefix . $hexByte . '000000' . $postfix_payload_hex_len) . $postfix_payload;
        }
        $buffer = bin2hex($buffer);
        $buffer1 = strtoupper(substr($buffer, 0, -16));

        $hex_crc = dechex(crc32(hex2bin($buffer1)));
        $hex_crc = str_pad($hex_crc, 8, "0", STR_PAD_LEFT);
        $buffer = substr($buffer, 0, -16) . ($hex_crc) . substr($buffer, -8);
        $data = $this->Tuya_send_receive(hex2bin($buffer), $local_ip);
        $result = substr($data, 20, -8);

        if (mb_substr($result, 0, 1) == '{') {
            return $result;
        } else if ($tuya_ver == '3.1') {
            $result = openssl_decrypt(base64_decode($result), 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
        } else {
            $result = openssl_decrypt($result, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
        }
        return $result;

    }

    function requestLocalStatus()
    {
        $devices = SQLSelect("SELECT * FROM tudevices WHERE LOCAL_KEY!='' && DEV_IP!='' ORDER BY DEV_ID");
        foreach ($devices as $device) {
            $mdev = strpos($device['DEV_ID'], '_');
            if ($mdev > 0 && substr($device['DEV_ID'], $mdev + 1) == 1) {
                $dev_id = substr($device['DEV_ID'], 0, $mdev);
                $status = '';
                $status = $this->TuyaLocalMsg('STATUS', $dev_id, $device['LOCAL_KEY'], $device['DEV_IP'], '', '', $device['TUYA_VER']);

                if ($status != '') {
                    // debmes('Status: '.$status.' '.$device['DEV_IP']);
                    $status = json_decode($status);
                    $dps = $status->dps;
                    foreach ($dps as $k => $d) {
                        if (is_bool($d)) {
                            $d = ($d) ? 1 : 0;
                        }
                        if ($k == '1') {
                            $k = 'state';
                            $this->processCommand($device['ID'], $k, $d);
                        } elseif ($k < 8) {
                            $dev_k = SQLSelectOne('SELECT ID FROM tudevices WHERE DEV_ID="' . DBSafe($dev_id . '_' . $k) . '"');
                            $k = 'state';
                            $this->processCommand($dev_k['ID'], $k, $d);
                        } else {
                            $this->processCommand($device['ID'], $k, $d);

                        }

                    }
                    $dps = json_encode($dps);
                    $data = $dps;
                    $this->processCommand($device['ID'], 'report', $data);
                }


            } else {
                $status = $this->TuyaLocalMsg('STATUS', $device['DEV_ID'], $device['LOCAL_KEY'], $device['DEV_IP'], '', '', $device['TUYA_VER']);
                if ($status != '') {
                    //debmes('Status: '.$status.' '.$device['DEV_IP']);
                    $status = json_decode($status);
                    $dps = $status->dps;
                    foreach ($dps as $k => $d) {
                        if (is_bool($d)) {
                            $d = ($d) ? 1 : 0;
                        }
                        if ($k == '1') {
                            $k = 'state';
                        }
                        $this->processCommand($device['ID'], $k, $d);
                    }
                    $dps = json_encode($dps);
                    $data = $dps;
                    $this->processCommand($device['ID'], 'report', $data);
                }

            }
        }
    }


    function TuyaLocalMsg34($command, $dev_id, $local_key, $local_ip, $dps, $cid, $dps12 = '')
    {
        $this->touchCycleHeartbeat();
        $real_local_key = $local_key;

        $hexByte = "03";
        $local_nonce = '0123456789abcdef';
        $json = '0123456789abcdef';

        // Запрос на ключ

        $payload = $this->encode_message($json, $local_key, $hexByte, 1);


        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 1, "usec" => 0));

        $buf = '';


        if (socket_connect($socket, $local_ip, 6668)) {
            for ($i = 0; $i < 3; $i++) {
                $send = socket_send($socket, $payload, strlen($payload), 0);
                if ($send != strlen($payload)) {
                    //debmes( date('y-m-d h:i:s') . ' sended '.$send .' from ' .strlen($payload) . 'ip' . $local_ip);
                }
                //$reciv=socket_recv ( $socket , $buf , 2048 ,0);
                $buf = $this->socket_read_tuya($socket);
                //debmes( date('y-m-d h:i:s') . ' recived '.strlen($buf));
                if ($buf != '') break;
                sleep(1);
            }

        } else {
            $err = socket_last_error($socket);
            echo date('y-m-d h:i:s') . ' ' . socket_strerror($err) . ' ' . $local_ip . "\n";
        }


        $data = $buf;
        $data = bin2hex($data);

        $payload_offset = (16 + 4) * 2;
        $payload_len = hexdec((substr($data, 28, 4))) - 4;
        $payload = substr($data, $payload_offset, $payload_len * 2);
        $crc = substr($payload, strlen($payload) - 8 - 64, 64);
        $suffix = substr($payload, strlen($payload) - 8, 8);
        $payload = substr($payload, 0, strlen($payload) - 8 - 64);


        // CRC check

        $have_crc = hash_hmac('sha256', hex2bin(substr($data, 0, strlen($data) - 8 - 64)), $local_key);

        if ($have_crc != $crc) {
            DebMes('Устройство ' . $dev_id . ' (' . $local_ip . '): неверная контрольная сумма (CRC) при установке защищённого соединения. Возможно, неверный локальный ключ.', 'tuya');
        }

        $payload = openssl_decrypt(hex2bin($payload), 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);
        $remote_nonce = substr(bin2hex($payload), 0, 32);
        $hmac_check = hash_hmac('sha256', ($local_nonce), $local_key);
        $have_hmac = bin2hex(substr($payload, 16, 32));

        if ($hmac_check != $have_hmac) {
            DebMes('Устройство ' . $dev_id . ' (' . $local_ip . '): неверная HMAC-подпись при установке защищённого соединения. Возможно, неверный локальный ключ.', 'tuya');
        }

        // Encrypt remote_nonce && send SESS_KEY_NEG_FINISH
        $rkey_hmac = hash_hmac('sha256', hex2bin($remote_nonce), ($local_key));

        // send command=5
        //MessagePayload(SESS_KEY_NEG_FINISH, rkey_hmac)

        $hexByte = "05";
        $prefix = "000055aa00000002000000";
        $payload = $this->encode_message(hex2bin($rkey_hmac), $local_key, $hexByte, 2);


        $send = socket_send($socket, $payload, strlen($payload), 0);

        if ($send != strlen($payload)) {
            DebMes(date('y-m-d h:i:s') . ' sended ' . $send . ' from ' . strlen($payload) . 'ip' . $local_ip);
        }


        $remote_nonce = hex2bin($remote_nonce);

        for ($i = 0; $i < strlen($local_nonce); $i++) {
            $hex_ary[] = sprintf("%02X", ord(substr($local_nonce, $i, 1)) ^ ord(substr($remote_nonce, $i, 1)));
        }

        $local_key = implode('', $hex_ary);
        $local_key = openssl_encrypt(hex2bin($local_key), 'AES-128-ECB', $real_local_key, OPENSSL_RAW_DATA);
        $local_key = substr($local_key, 0, 16);


        if ($command == 'STATUS') {

            $sequenceN = 3;

            if ($dps12 != '') {
                $payload_12 = $this->encode_message($dps12, $local_key, "12", $sequenceN);
                $send = socket_send($socket, $payload_12, strlen($payload_12), 0);
                $buf1 = '';
                $reciv = socket_recv($socket, $buf1, 2048, MSG_WAITALL);
                $result = substr($buf1, 20, -36);
                $result = openssl_decrypt($result, 'AES-128-ECB', ($local_key), OPENSSL_RAW_DATA);
                //debmes('3.4 12 add receiv '.$result);
                $sequenceN = 4;
            }


            if ($cid == '') {
                $json = '{}';
            } else {
                $json = '{"cid":"' . $cid . '"}';
            }

            $hexByte = "10";

            $buf = '';

            $payload = $this->encode_message($json, $local_key, $hexByte, $sequenceN);

            $send = socket_send($socket, $payload, strlen($payload), 0);

            //$reciv=socket_recv ( $socket , $buf , 2048 ,MSG_WAITALL);

            $buf = $this->socket_read_tuya($socket);

            $data = $buf;

            $result = substr($data, 20, -36);

            socket_close($socket);


            return openssl_decrypt($result, 'AES-128-ECB', ($local_key), OPENSSL_RAW_DATA);

        } else {

            if ($cid == '') {
                $json = '3.4' . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . '{"protocol":5,"t":' . time() . ',"data":{"dps":' . $dps . '}}';
            } else {
                $json = '3.4' . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . '{"protocol":5,"t":' . time() . ',"data":{"dps":' . $dps . ',"cid":"' . $cid . '"}}';
            }
            $hexByte = "0d";


            $payload = $this->encode_message($json, ($local_key), $hexByte, 3);

            $send = socket_send($socket, $payload, strlen($payload), 0);

            $buf = $this->socket_read_tuya($socket);

        }

        socket_close($socket);

    }

    function socket_read_tuya($socket)
    {
        // 1. Читаем заголовок (16 байт)
        $header = socket_read($socket, 16);
        if (!$header) return false;

        // 2. Извлекаем длину (она находится в байтах 12-15)
        // Формат 'N' - unsigned long 32 bit, big endian
        $len_hex = bin2hex(substr($header, 12, 4));
        $payload_len = hexdec($len_hex);

        // 3. Читаем тело пакета (payload + HMAC + suffix)
        $body = "";
        while (strlen($body) < $payload_len) {
            $chunk = socket_read($socket, $payload_len - strlen($body));
            if (!$chunk) break;
            $body .= $chunk;
        }

        return $header . $body;
    }


    function tuyaLocalMsg35($command, $dev_id, $local_key, $local_ip, $dps, $cid, $dps12 = '')
    {
        $this->touchCycleHeartbeat();

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 2, "usec" => 0]);
        socket_connect($sock, $local_ip, 6668);

        // Рукопожатие
        $sessionKey = $this->negotiateSessionKey($local_key, $dev_id, $sock);
        if (!$sessionKey) {
            debmes('Ошибка рукопожатия с устройством ' . $dev_id . ' (' . $local_ip . '). Проверьте локальный ключ и доступность устройства.', 'tuya');
            socket_close($sock);
            return;
        }

        if ($command == 'STATUS') {

            $sequenceN = 3;

            if ($dps12 != '') {
                $payload_12 = $this->encodeMessage35($dps12, $sessionKey, 0X12, $sequenceN);
                $send = socket_send($sock, $payload_12, strlen($payload_12), 0);
                $buf1 = '';
                $reciv = socket_recv($sock, $buf1, 2048, MSG_WAITALL);
                $result = $this->decodeMessage35($buf1, $sessionKey);
                $sequenceN = 4;
            }

            if ($cid == '') {
                $json = '{}';
            } else {
                $json = '{"cid":"' . $cid . '"}';
            }


            $hexByte = 0X10;


            $buf = '';

            $payload = $this->encodeMessage35($json, $sessionKey, $hexByte, $sequenceN);

            $send = socket_send($sock, $payload, strlen($payload), 0);

            $reciv = socket_recv($sock, $buf, 2048, MSG_WAITALL);

            $result = $this->decodeMessage35($buf, $sessionKey);

            $result = str_replace("\x00", "", $result);

            socket_close($sock);

            return $result;

        } else {

            if ($cid == '') {
                $json = '3.5' . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . '{"protocol":5,"t":' . time() . ',"data":{"dps":' . $dps . '}}';
            } else {
                $json = '3.5' . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . '{"protocol":5,"t":' . time() . ',"data":{"dps":' . $dps . ',"cid":"' . $cid . '"}}';
            }
            $hexByte = 0x0D;

            $payload = $this->encodeMessage35($json, $sessionKey, $hexByte, 3);

            $send = socket_send($sock, $payload, strlen($payload), 0);

            $reciv = socket_recv($sock, $buf, 2048, MSG_WAITALL);

        }

        socket_close($sock);

    }

    function negotiateSessionKey($local_key, $dev_id, $sock)
    {
        // Шаг 1: отправка local_nonce
        $localNonce = '0123456789abcdef';
        $seq = 1;
        $iv = str_pad(substr((string)(time() * 1000), 0, 12), 12, "\0", STR_PAD_RIGHT);
        $msg_len = strlen($localNonce) + 28;
        $header = pack("N", 0x00006699) . pack("n", 0x0000) . pack("N", $seq) . pack("N", 0x03) . pack("N", $msg_len);
        $aad = substr($header, 4);
        $encrypted = openssl_encrypt($localNonce, 'aes-128-gcm', $local_key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        $packet = $header . $iv . $encrypted . $tag . pack("N", 0x00009966);
        socket_send($sock, $packet, strlen($packet), 0);

        // Шаг 2: получение remote_nonce + HMAC
        $buf = '';
        socket_recv($sock, $buf, 2048, MSG_WAITALL);
        $decrypted = $this->decodeMessage35($buf, $local_key);
        $remoteNonce = substr($decrypted, 4, 16);
        $hmacReceived = substr($decrypted, 20, 32);
        $hmacExpected = hash_hmac('sha256', $localNonce, $local_key, true);

        if ($hmacExpected !== $hmacReceived) {
            debmes('Устройство ' . $dev_id . ': HMAC не совпадает при установке сессионного ключа. Проверьте локальный ключ устройства.', 'tuya');
            return '';
        }

        // Шаг 3: отправка HMAC(remote_nonce)
        $seq = 2;
        $rkeyHmac = hash_hmac('sha256', $remoteNonce, $local_key, true);
        $iv3 = str_pad(substr((string)(time() * 1000), 0, 12), 12, "\0", STR_PAD_RIGHT);
        $msg_len3 = strlen($rkeyHmac) + 28;
        $header3 = pack("N", 0x00006699) . pack("n", 0x0000) . pack("N", $seq) . pack("N", 0x05) . pack("N", $msg_len3);
        $aad3 = substr($header3, 4);
        $encrypted3 = openssl_encrypt($rkeyHmac, 'aes-128-gcm', $local_key, OPENSSL_RAW_DATA, $iv3, $tag3, $aad3, 16);
        $packet3 = $header3 . $iv3 . $encrypted3 . $tag3 . pack("N", 0x00009966);
        socket_send($sock, $packet3, strlen($packet3), 0);

        // Генерация sessionKey
        $xored = '';
        for ($i = 0; $i < 16; $i++) {
            $xored .= chr(ord($localNonce[$i]) ^ ord($remoteNonce[$i]));
        }
        $ivFinal = substr($localNonce, 0, 12);
        $encryptedFinal = openssl_encrypt($xored, 'aes-128-gcm', $local_key, OPENSSL_RAW_DATA, $ivFinal, $tagFinal, '', 16);

        return substr($encryptedFinal, 0, 16);
    }


    function encode_message($json, $local_key, $hexByte, $seqno)
    {
        $prefix = "000055aa00000000000000";
        $prefix = "000055aa0000000" . $seqno . "000000";
        $suffix = "0000aa55";


        $json_payload = openssl_encrypt($json, 'AES-128-ECB', $local_key, OPENSSL_RAW_DATA);

        $postfix_payload = hex2bin(bin2hex($json_payload) . $suffix);
        $postfix_payload_hex_len = dechex(strlen($json_payload) + 36);


        if (strlen($postfix_payload_hex_len) > 2) {
            $buffer = hex2bin($prefix . $hexByte . '00000' . $postfix_payload_hex_len . bin2hex($json_payload));
        } else {
            $buffer = hex2bin($prefix . $hexByte . '000000' . $postfix_payload_hex_len . bin2hex($json_payload));
        }
        $buffer = bin2hex($buffer);

        $buffer1 = strtoupper(($buffer));

        $hex_crc = (hash_hmac('sha256', hex2bin($buffer1), $local_key));

        $hex_crc = str_pad($hex_crc, 8, "0", STR_PAD_LEFT);

        $buffer = $buffer1 . $hex_crc . $suffix;

        return hex2bin($buffer);


    }

    function encodeMessage35($json, $key, $cmd, $seq)
    {

        //$iv = str_pad(substr((string)(time() * 1000), 0, 12), 12, "\0", STR_PAD_RIGHT);
        $iv = hex2bin('31373539393636363737312e');
        $msg_len = strlen($json) + 28;
        $header = pack("N", 0x00006699) . pack("n", 0x0000) . pack("N", $seq) . pack("N", $cmd) . pack("N", $msg_len);
        $aad = substr($header, 4);
        $encrypted = openssl_encrypt($json, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        $packet = $header . $iv . $encrypted . $tag . pack("N", 0x00009966);
        return $packet;
    }

    function decodeMessage35($packet, $key)
    {
        if (substr($packet, 0, 4) !== pack("N", 0x00006699)) return null;
        $len = unpack("N", substr($packet, 14, 4))[1];
        $aad = substr($packet, 4, 14);
        $iv = substr($packet, 18, 12);
        $encrypted = substr($packet, 30, $len - 28);
        $tag = substr($packet, 18 + $len - 16, 16);
        return openssl_decrypt($encrypted, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
    }


    function Tuya_send_receive($payload, $local_ip)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 1, "usec" => 0));
        //socket_set_option($socket, SOL_SOCKET, TCP_NODELAY, 1);

        $buf = '';

        if (socket_connect($socket, $local_ip, 6668)) {
            for ($i = 0; $i < 3; $i++) {
                $send = socket_send($socket, $payload, strlen($payload), 0);
                if ($send != strlen($payload)) {
                    //debmes( date('y-m-d h:i:s') . ' sended '.$send .' from ' .strlen($payload) . 'ip' . $local_ip);
                }
                $reciv = socket_recv($socket, $buf, 2048, 0);
                //debmes( date('y-m-d h:i:s') . ' recived '.strlen($buf));
                if ($buf != '') break;
                sleep(1);
            }

        } else {
            $err = socket_last_error($socket);
            echo date('y-m-d h:i:s') . ' ' . socket_strerror($err) . ' ' . $local_ip . "\n";
        }

        socket_close($socket);
        return $buf;
    }

    function Tuya_Discovery_Devices($token)
    {
        return;
        $sURL = 'https://px1.tuyaeu.com/homeassistant/skill';

        $header = [
            'name' => 'Discovery',
            'namespace' => 'discovery',
            'payloadVersion' => 1,
        ];

        $payload['accessToken'] = $token;

        $data = [
            'header' => $header,
            'payload' => $payload,
        ];


        $aHTTP = array(
            'http' =>
                array(
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($data, JSON_FORCE_OBJECT)
                )
        );
        $context = stream_context_create($aHTTP);
        $contents = file_get_contents($sURL, false, $context);
        $result = json_decode($contents);
        if ($result->header->code != 'SUCCESS') {
            debmes('Ошибка HA-облака Tuya при обнаружении устройств: ' . ($result->header->msg ?? 'неизвестная ошибка') . '. Код: ' . ($result->header->code ?? 'N/A') . '. Проверьте токен доступа.', 'tuya');
            return;
        }
        foreach ($result->payload->devices as $device) {

            $rec = SQLSelectOne('select * from tudevices where DEV_ID="' . $device->id . '"');

            if ($rec == NULL) {
                $rec['TITLE'] = $device->name;
                $rec['DEV_ICON'] = $device->icon;
                $rec['DEV_ID'] = $device->id;
                $rec['TYPE'] = $device->dev_type;
                $rec['SEND12'] = 0;
                $rec['VER_3_1'] = 0;
                $rec['IR_FLAG'] = 0;

                $rec['ID'] = SQLInsert('tudevices', $rec);
            }

            $data = '';
            if ($rec['STATUS'] == 5) {
                foreach ($device->data as $key => $value) {
                    if (is_bool($value)) {
                        $value = (($value) ? 1 : 0);
                        $data .= $key . ':' . (($value) ? 1 : 0) . ' ';
                    } else if ($value == 'true') {
                        $value = 1;
                        $data .= $key . ':' . $value . ' ';
                    } else if ($value == 'false') {

                        $value = 0;
                        $data .= $key . ':' . $value . ' ';
                    } else {
                        $data .= $key . ':' . $value . ' ';
                    }
                    $this->processCommand($rec['ID'], $key, $value);
                }
            }

        }
    }

    function TuyaWebRequest($options, $v = '1.0')
    {
        // Запрос к облаку блокирующий: сначала отмечаемся в watchdog-е цикла.
        $this->touchCycleHeartbeat();

        $this->getConfig();
        $sid = $this->config['TUYA_SID'];
        $endpoint = $this->config['TUYA_WEB_ENDPOINT'];
        $d = time();

        if ($this->config['TUYA_BZTYPE'] == 'tuya') {
            $key = '3fjrekuxank9eaej3gcx';
            $secret = 'aq7xvqcyqcnegvew793pqjmhv77rneqc';
            $secret2 = 'vay9g59g9g99qf3rtqptmc3emhkanwkx';
            $certSign = '93:21:9F:C2:73:E2:20:0F:4A:DE:E5:F7:19:1D:C6:56:BA:2A:2D:7B:2F:F5:D2:4C:D5:5C:4B:61:55:00:1E:40';
        } else {
            $key = 'ekmnwp9f5pnh3trdtpgy';
            $secret = 'r3me7ghmxjevrvnpemwmhw3fxtacphyg';
            $secret2 = 'jfg5rs5kkmrj5mxahugvucrsvw43t48x';
            $certSign = '0F:C3:61:99:9C:C0:C3:5B:A8:AC:A5:7D:AA:55:93:A2:0C:F5:57:27:70:2E:A8:5A:D7:B3:22:89:49:F8:88:FE';
        }

        $keyHmac = $certSign . '_' . $secret2 . '_' . $secret;

        if (isset($options['deviceID'])) {
            $deviceID = $options['deviceID'];
        } else {
            $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz';
            $deviceID = substr(str_shuffle($permitted_chars), 0, 44);
        }

        $pairs = ['a' => $options['action'],
            'deviceId' => $deviceID,
            'os' => 'Linux',
            'lang' => 'en',
            'v' => $v,
            'clientId' => $key,
            'time' => $d];

        if (isset($options['data'])) {
            $pairs['postData'] = json_encode($options['data']);
        }

        if (isset($options['gid'])) {
            $pairs['gid'] = $options['gid'];
        }


        $pairs['et'] = '0.0.1';
        $pairs['ttid'] = $this->config['TUYA_BZTYPE'];
        $pairs['appVersion'] = '3.8.5';


        if ($options['requiresSID'] == 1) {
            $pairs['sid'] = $sid;
        }

        // Generate signature for request
        $valuesToSign = ['a', 'v', 'lat', 'lon', 'lang', 'deviceId', 'imei',
            'imsi', 'appVersion', 'ttid', 'isH5', 'h5Token', 'os',
            'clientId', 'postData', 'time', 'requestId', 'n4h5', 'sid',
            'sp', 'et'];

        $sorted_pairs = $pairs;
        ksort($sorted_pairs);
        $strToSign = '';

        // Create string to sign
        foreach ($sorted_pairs as $key => $value) {
            if (!in_array($key, $valuesToSign) || empty($sorted_pairs [$key])) {
                continue;
            } else if ($key === 'postData') {

                if ($strToSign) {
                    $strToSign .= '||';
                }
                $strToSign .= $key;
                $strToSign .= '=';
                $strToSign .= $this->Tuya_mobileHash($pairs[$key]);
            } else {

                if ($strToSign) {
                    $strToSign .= '||';
                }

                $strToSign .= $key;
                $strToSign .= '=';
                $strToSign .= $pairs[$key];
            }
        }


        $pairs['sign'] = hash_hmac('sha256', $strToSign, $keyHmac);
        $result = '';

        // getURL по умолчанию ждёт до 45 секунд; для опроса этого мало.
        $result = getURL($endpoint . '?' . http_build_query($pairs), 0, '', '', false, array(
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
        ));

        return $result;

    }


    function Tuya_mobileHash($hash)
    {
        $preHash = md5($hash);

        return substr($preHash, 8, 8) . substr($preHash, 0, 8) . substr($preHash, 24, 8) . substr($preHash, 16, 8);
    }

    function Tuya_Web_Login()
    {
        $this->getConfig();
        $region = 'EU';
        $email = $this->config['TUYA_USERNAME'];
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.user.email.token.create',
            'data' => ['countryCode' => $region,
                'email' => $email],
            'requiresSID' => 0]);

        $result = json_decode($apiResult, true);
        if (!$result['success']) {
            $errorCode = $result['errorCode'] ?? 'UNKNOWN';
            $errorMsg = $result['msg'] ?? '';
            debmes('Ошибка получения публичного ключа Tuya: ' . $errorCode . '. Пользователь: ' . $email . ', регион: ' . $region . ($errorMsg ? '. Сообщение: ' . $errorMsg : ''), 'tuya');
            return;
        }
        $n = $result["result"]["publicKey"];
        $e = $result["result"]["exponent"];
        $token = $result["result"]["token"];

        $data = md5($this->config['TUYA_PASSWD']);
        if (extension_loaded('bcmath')) {
            $data_dec = $this->bytes_to_int($data);
            $encryptedPass = bcpowmod($data_dec, $e, $n);
            $encryptedPass = str_pad($this->bcdechex($encryptedPass), 256, '0', STR_PAD_LEFT);
        } else {
            $a = exec('python3 ' . __DIR__ . '/pow_python.py ' . $n . ' ' . $e . ' ' . $data);
            if ($a == '') {
                debmes('Не удалось выполнить Python-скрипт шифрования пароля для Tuya. Проверьте наличие python3 и модуля pow_python.py. Пользователь: ' . $email, 'tuya');
                return;
            }
            $encryptedPass = substr($a, 2, strlen($a) - 3);
        }
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.user.email.password.login',
            'data' => ['countryCode' => $region,
                'email' => $email,
                'passwd' => $encryptedPass,
                'ifencrypt' => 1,
                'options' => ['group' => 1],
                'token' => $token],
            'requiresSID' => 0], '2.0');
        $result = json_decode($apiResult, true);
        if (!$result['success']) {
            $errorCode = $result['errorCode'] ?? 'UNKNOWN';
            $errorMsg = $result['msg'] ?? '';
            debmes('Не удалось получить SID сессии Tuya: ' . $errorCode . '. Пользователь: ' . $email . ', регион: ' . $region . ($errorMsg ? '. Сообщение: ' . $errorMsg : '') . '. Проверьте логин/пароль.', 'tuya');
        } else {
            $this->config['TUYA_SID'] = $result['result']['sid'];
            $this->config['TUYA_UID'] = $result['result']['uid'];
            $this->config['TUYA_ECODE'] = $result['result']['ecode'];
            $this->config['TUYA_PUBKEY'] = $n;
            $this->config['TUYA_WEB_ENDPOINT'] = $result['result'] ['domain']['mobileApiUrl'] . '/api.json';
            $this->saveConfig();
        }
        return $result;
    }

    function Tuya_Web_Scheme($gid)
    {
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.device.ref.info.my.list',
            'gid' => $gid,
            'requiresSID' => 1]);
        $result = json_decode($apiResult, true);

        $sc = array();

        foreach ((array)($result['result'] ?? array()) as $scheme) {
            foreach ((array)json_decode((string)($scheme['schemaInfo']['schema'] ?? ''), true) as $dp) {
                if (!isset($dp['mode'])) $dp['mode'] = '';
                if (!isset($dp['code'])) $dp['code'] = '';
                if (!isset($dp['property']['min'])) $dp['property']['min'] = '';
                if (!isset($dp['property']['max'])) $dp['property']['max'] = '';
                if (!isset($dp['property']['scale'])) $dp['property']['scale'] = '';
                if (!isset($dp['property']['unit'])) $dp['property']['unit'] = '';
                if (!isset($dp['property']['type'])) $dp['property']['type'] = '';


                $sc[$scheme['id']][$dp['id']]['mode'] = $dp['mode'];
                $sc[$scheme['id']][$dp['id']]['code'] = $dp['code'];
                $sc[$scheme['id']][$dp['id']]['min'] = $dp['property']['min'];
                $sc[$scheme['id']][$dp['id']]['max'] = $dp['property']['max'];
                $sc[$scheme['id']][$dp['id']]['scale'] = $dp['property']['scale'];
                $sc[$scheme['id']][$dp['id']]['unit'] = $dp['property']['unit'];
                $sc[$scheme['id']][$dp['id']]['type'] = $dp['property']['type'];

                if (isset($dp['property']['range'])) {
                    foreach ($dp['property']['range'] as $key => $value) {
                        $sc[$scheme['id']][$dp['id']]['range'][$key] = $value;
                    }
                }
            }
        }

        return $sc;

    }


    function bchexdec($hex)
    {
        if (strlen($hex) == 1) {
            return hexdec($hex);
        } else {
            $remain = substr($hex, 0, -1);
            $last = substr($hex, -1);
            return bcadd(bcmul(16, $this->bchexdec($remain)), hexdec($last));
        }
    }


    function bytes_to_int($bytes)
    {
        $result = '0';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $b = strval(ord($bytes[$i]));
            $result = bcadd(bcmul($result, 256), $b);
        }
        return $result;
    }


    function bcdechex($dec)
    {
        $last = bcmod($dec, 16);
        $remain = bcdiv(bcsub($dec, $last), 16);

        if ($remain == 0) {
            return dechex($last);
        } else {
            return $this->bcdechex($remain) . dechex($last);
        }
    }

    function Tuya_Web_Status()
    {
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.location.list',
            'requiresSID' => 1]);
        $result = json_decode($apiResult, true);

        if (empty($result['result'])) return;

        foreach ($result['result'] as $home) {
            $gid = $home['groupId'];

            $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.my.group.device.list',
                'gid' => $gid,
                'requiresSID' => 1]);

            $result = json_decode($apiResult, true);

            foreach ((array)($result['result'] ?? array()) as $device) {
                if (isset($device['moduleMap']['infrared'])) {
                    continue;
                }

                $rec = SQLSelectOne('select * from tudevices where DEV_ID="' . DBSafe($device['devId']) . '"');


                if (!isset($device['meshId'])) {
                    $device['meshId'] = '';
                }


                if (empty($rec)) {

                    $rec['IR_FLAG'] = 0;
                    $rec['TITLE'] = $device['name'];
                    $rec['DEV_ICON'] = $device['iconUrl'];
                    $rec['DEV_ID'] = $device['devId'];
                    $rec['TYPE'] = $device['category'];
                    $rec['LOCAL_KEY'] = $device['localKey'];
                    $rec['PRODUCT_ID'] = $device['productId'];
                    $rec['GID_ID'] = $gid;
                    $rec['MESH_ID'] = $device['meshId'];
                    $rec['MAC'] = $device['mac'];
                    $rec['SEND12'] = 0;
                    $rec['VER_3_1'] = 0;
                    $rec['TUYA_VER'] = '3.3';
                    $rec['STATUS'] = 0;
                    $rec['CONTROL'] = 0;
                    $rec['UPDATED'] = date('y-m-d H:i:s', time());
                    $rec['DSP_FILLED'] = 0;
                    $rec['UUID'] = isset($device['nodeId']) ? $device['nodeId'] : $device['uuid'];
                    $rec['ID'] = SQLInsert('tudevices', $rec);
                } else {
                    if (!isset($rec['MAC'])) $rec['MAC'] = '';
                    if (!isset($rec['LOCAL_KEY'])) $rec['LOCAL_KEY'] = '';
                    if (!isset($rec['MESH_ID'])) $rec['MESH_ID'] = '';
                    if (!isset($rec['IR_FLAG'])) $rec['IR_FLAG'] = 0;
                    if (!isset($rec['UUID'])) $rec['UUID'] = '';

                    $uuid = isset($device['nodeId']) ? $device['nodeId'] : $device['uuid'];

                    if ($rec['IR_FLAG'] != 0 || $rec['MAC'] != $device['mac'] || $rec['LOCAL_KEY'] != $device['localKey'] || $rec['PRODUCT_ID'] != $device['productId'] || $rec['GID_ID'] != $gid || $rec['MESH_ID'] != $device['meshId'] || $rec['UUID'] != $uuid) {
                        $rec['LOCAL_KEY'] = $device['localKey'];
                        $rec['PRODUCT_ID'] = $device['productId'];
                        $rec['GID_ID'] = $gid;
                        $rec['MESH_ID'] = $device['meshId'];
                        $rec['MAC'] = $device['mac'];
                        $rec['IR_FLAG'] = 0;
                        $rec['UUID'] = $uuid;
                        $rec['UPDATED'] = date('y-m-d H:i:s', time());

                        $rec['ID'] = SQLUpdate('tudevices', $rec);
                    }

                }

                $data = '';
                if (substr((string)($device['categoryCode'] ?? ''), 0, 3) == 'wf_') {
                    if ($rec['STATUS'] == 0) {
                        if (!empty($device['moduleMap']['wifi']['isOnline'])) {
                            $this->processCommand($rec['ID'], 'online', 1);
                        } else {
                            $this->processCommand($rec['ID'], 'online', 0);
                        }
                    }
                } else if (substr((string)($device['categoryCode'] ?? ''), 0, 4) == 'zig_') {
                    if (!empty($device['moduleMap']['zigbee']['isOnline'])) {
                        $this->processCommand($rec['ID'], 'online', 1);
                    } else {
                        $this->processCommand($rec['ID'], 'online', 0);
                    }
                } else if (substr((string)($device['categoryCode'] ?? ''), 0, 4) == 'sub_') {
                    if (!empty($device['moduleMap']['subpieces']['isOnline'])) {
                        $this->processCommand($rec['ID'], 'online', 1);
                    } else {
                        $this->processCommand($rec['ID'], 'online', 0);
                    }
                } else if (substr((string)($device['categoryCode'] ?? ''), 0, 4) == 'sig_') {
                    if (!empty($device['moduleMap']['bluetooth']['isOnline'])) {
                        $this->processCommand($rec['ID'], 'online', 1);
                    } else {
                        $this->processCommand($rec['ID'], 'online', 0);
                    }
                }

                if ($rec['STATUS'] == 0) {
                    foreach ((array)($device['dps'] ?? array()) as $key => $value) {

                        if (is_bool($value)) {
                            $value = (($value) ? 1 : 0);
                            $data .= $key . ':' . (($value) ? 1 : 0) . ' ';
                        } else if ($value == 'true') {
                            $value = 1;
                            $data .= $key . ':' . $value . ' ';
                        } else if ($value == 'false') {

                            $value = 0;
                            $data .= $key . ':' . $value . ' ';
                        } else {
                            $data .= $key . ':' . $value . ' ';
                        }
                        $this->processCommand($rec['ID'], $key, $value);
                    }

                }
            }
            // Синхронизация — один раз на дом, а не на каждое устройство: иначе
            // цикл делал по два лишних запроса в облако на каждое устройство.
            $this->syncDeletedDevices($gid);
            $this->syncDeviceTitles($gid);
        }
    }

    function Tuya_Web_Discovery_Devices()
    {
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.location.list',
            'requiresSID' => 1]);
        $result = json_decode($apiResult, true);

        foreach ((array)($result['result'] ?? array()) as $home) {
            $gid = $home['groupId'];
            $sc = $this->Tuya_Web_Scheme($gid);
            $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.my.group.device.list',
                'gid' => $gid,
                'requiresSID' => 1]);

            $result = json_decode($apiResult, true);
            foreach ((array)($result['result'] ?? array()) as $device) {
                if (isset($device['moduleMap']['infrared'])) {
                    continue;
                }

                $rec = SQLSelectOne('select * from tudevices where DEV_ID="' . DBSafe($device['devId']) . '"');


                if (!isset($device['meshId'])) {
                    $device['meshId'] = '';
                }

                if ($rec == NULL) {

                    $rec['IR_FLAG'] = 0;
                    $rec['TITLE'] = $device['name'];
                    $rec['DEV_ICON'] = $device['iconUrl'];
                    $rec['DEV_ID'] = $device['devId'];
                    $rec['TYPE'] = $device['category'];
                    $rec['LOCAL_KEY'] = $device['localKey'];
                    $rec['PRODUCT_ID'] = $device['productId'];
                    $rec['GID_ID'] = $gid;
                    $rec['MESH_ID'] = $device['meshId'];
                    $rec['MAC'] = $device['mac'];
                    $rec['SEND12'] = '0';
                    $rec['VER_3_1'] = '0';
                    $rec['TUYA_VER'] = '3.3';
                    $rec['STATUS'] = 0;
                    $rec['CONTROL'] = 0;
                    $rec['UUID'] = isset($device['nodeId']) ? $device['nodeId'] : $device['uuid'];
                    $rec['UPDATED'] = date('y-m-d H:i:s', time());
                    $rec['DSP_FILLED'] = 0;

                    $rec['ID'] = SQLInsert('tudevices', $rec);
                    $dsp_filled = '0';
                } else {

                    if (!isset($rec['MAC'])) $rec['MAC'] = '';
                    if (!isset($rec['LOCAL_KEY'])) $rec['LOCAL_KEY'] = '';
                    if (!isset($rec['MESH_ID'])) $rec['MESH_ID'] = '';
                    if (!isset($rec['IR_FLAG'])) $rec['IR_FLAG'] = 0;
                    if (!isset($rec['UUID'])) $rec['UUID'] = '';

                    $uuid = isset($device['nodeId']) ? $device['nodeId'] : $device['uuid'];

                    if ($rec['UUID'] != $uuid || $rec['IR_FLAG'] != 0 || $rec['MAC'] != $device['mac'] || $rec['LOCAL_KEY'] != $device['localKey'] || $rec['PRODUCT_ID'] != $device['productId'] || $rec['GID_ID'] != $gid || $rec['MESH_ID'] != $device['meshId']) {
                        $rec['LOCAL_KEY'] = $device['localKey'];
                        $rec['PRODUCT_ID'] = $device['productId'];
                        $rec['GID_ID'] = $gid;
                        $rec['MESH_ID'] = $device['meshId'];
                        $rec['MAC'] = $device['mac'];
                        $rec['IR_FLAG'] = 0;
                        $rec['UUID'] = $uuid;
                        $rec['UPDATED'] = date('y-m-d H:i:s', time());

                        $rec['ID'] = SQLUpdate('tudevices', $rec);
                    }
                    $dsp_filled = (int)$rec['DSP_FILLED'];
                }

                $data = '';
                if (substr((string)($device['categoryCode'] ?? ''), 0, 3) == 'wf_') {
                    if (!empty($device['moduleMap']['wifi']['isOnline'])) {
                        $this->processCommand($rec['ID'], 'online', 1);
                    } else {
                        $this->processCommand($rec['ID'], 'online', 0);
                    }
                } else if (substr((string)($device['categoryCode'] ?? ''), 0, 4) == 'zig_') {
                    if (!empty($device['moduleMap']['zigbee']['isOnline'])) {
                        $this->processCommand($rec['ID'], 'online', 1);
                    } else {
                        $this->processCommand($rec['ID'], 'online', 0);
                    }
                }


                if ($dsp_filled == 0) {
                    foreach ((array)($device['dps'] ?? array()) as $key => $value) {
                        $cmd_rec = SQLSelectOne("SELECT * FROM tucommands WHERE DEVICE_ID=" . (int)$rec['ID'] . " AND TITLE LIKE '" . DBSafe($key) . "'");

                        if (empty($cmd_rec)) {
                            $cmd_rec = array();
                            $cmd_rec['TITLE'] = $key;
                            $cmd_rec['VALUE_MIN'] = ($sc[$device['productId']][$key]['min'] ?? '');
                            $cmd_rec['MODE'] = ($sc[$device['productId']][$key]['mode'] ?? '');
                            $cmd_rec['ALIAS'] = ($sc[$device['productId']][$key]['code'] ?? '');
                            $cmd_rec['VALUE_UNIT'] = ($sc[$device['productId']][$key]['unit'] ?? '');
                            $cmd_rec['VALUE_TYPE'] = ($sc[$device['productId']][$key]['type'] ?? '');

                            $cmd_rec['VALUE_MAX'] = ($sc[$device['productId']][$key]['max'] ?? '');
                            $cmd_rec['VALUE_SCALE'] = ($sc[$device['productId']][$key]['scale'] ?? '');
                            if ($cmd_rec['VALUE_SCALE'] == '') $cmd_rec['VALUE_SCALE'] = 0;
                            $cmd_rec['DIVIDEDBY2'] = 0;
                            $cmd_rec['DIVIDEDBY10'] = 0;
                            $cmd_rec['DIVIDEDBY100'] = 0;
                            $cmd_rec['POWER_METER'] = 0;
                            $cmd_rec['DECODE'] = 0;
                            $cmd_rec['SPLIT'] = 0;

                            $cmd_rec['DEVICE_ID'] = $rec['ID'];

                            $cmd_rec['ID'] = SQLInsert('tucommands', $cmd_rec);
                            $dsp_filled = 1;
                        } else if ($cmd_rec['ALIAS'] == '') {
                            $cmd_rec['VALUE_MIN'] = ($sc[$device['productId']][$key]['min'] ?? '');
                            $cmd_rec['MODE'] = ($sc[$device['productId']][$key]['mode'] ?? '');
                            $cmd_rec['ALIAS'] = ($sc[$device['productId']][$key]['code'] ?? '');

                            $cmd_rec['VALUE_UNIT'] = ($sc[$device['productId']][$key]['unit'] ?? '');
                            $cmd_rec['VALUE_MAX'] = ($sc[$device['productId']][$key]['max'] ?? '');

                            if ($cmd_rec['DIVIDEDBY2'] == 0 && $cmd_rec['DIVIDEDBY10'] == 0 && $cmd_rec['DIVIDEDBY100'] == 0) {
                                $cmd_rec['VALUE_SCALE'] = ($sc[$device['productId']][$key]['scale'] ?? '');
                            }

                            if ($cmd_rec['VALUE_SCALE'] == '') $cmd_rec['VALUE_SCALE'] = 0;
                            $cmd_rec['VALUE_TYPE'] = ($sc[$device['productId']][$key]['type'] ?? '');
                            $cmd_rec['ID'] = SQLUpdate('tucommands', $cmd_rec);
                            $dsp_filled = 1;
                        } else {
                            $dsp_filled = 0;
                        }

                        if (isset($sc[$device['productId']][$key]['range']) && $sc[$device['productId']][$key]['range']) {
                            foreach ($sc[$device['productId']][$key]['range'] as $range_key => $range_value) {
                                $rng_rec = SQLSelectOne("SELECT * FROM  turange WHERE COMMAND_ID=" . (int)$cmd_rec['ID'] . " AND RANGE_VALUE='" . $range_key . "'");
                                if (!$rng_rec['ID']) {
                                    $rng_rec = array();
                                    $rng_rec['COMMAND_ID'] = $cmd_rec['ID'];
                                    $rng_rec['RANGE_VALUE'] = $range_key;
                                    $rng_rec['RANGE_DESCRIPTION'] = $range_value;

                                    $rng_rec['ID'] = SQLInsert('turange', $rng_rec);
                                }
                            }
                        }

                        if (is_bool($value)) {
                            $value = (($value) ? 1 : 0);
                            $data .= $key . ':' . (($value) ? 1 : 0) . ' ';
                        } else if ($value == 'true') {
                            $value = 1;
                            $data .= $key . ':' . $value . ' ';
                        } else if ($value == 'false') {

                            $value = 0;
                            $data .= $key . ':' . $value . ' ';
                        } else {
                            $data .= $key . ':' . $value . ' ';
                        }
                        $this->processCommand($rec['ID'], $key, $value);


                    }

                    if ($dsp_filled == 1) {
                        $rec['DSP_FILLED'] = 1;
                        $rec['ID'] = SQLUpdate('tudevices', $rec);
                    }
                }


            }
        }
    }

    function Tuya_Web_DP($device_id, $value, $dps_name, $gid, $gw_id)
    {
        if (empty($gw_id)) {
            $gw_id = $device_id;
        }


        $rec = SQLSelectOne("select VALUE_TYPE from tucommands tc inner join tudevices td ON tc.DEVICE_ID=td.ID where tc.TITLE='" . $dps_name . "' && td.DEV_ID='" . $device_id . "'");

        if ($rec['VALUE_TYPE'] == 'bool') {
            $value = (($value == 1) ? 'true' : 'false');
        } else if ($rec['VALUE_TYPE'] == 'value') {
            $value = (int)$value;
        } else if ($rec['VALUE_TYPE'] == 'string' || $rec['VALUE_TYPE'] == 'enum' || !is_numeric($value)) {
            $value = "'$value'";
        }


        $dps = '{' . $dps_name . ':' . $value . '}';

        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.device.dp.publish',
            'gid' => $gid,
            'data' => ['devId' => $device_id,
                'gwId' => $gw_id,
                'dps' => $dps],
            'requiresSID' => 1]);

        $result = json_decode($apiResult, true);
        if (!$result['success']) {
            $deviceInfo = SQLSelectOne("SELECT TITLE FROM tudevices WHERE DEV_ID='" . DBSafe($device_id) . "'");
            $deviceTitle = $deviceInfo['TITLE'] ?? $device_id;
            $errorCode = $result['errorCode'] ?? 'UNKNOWN';
            $errorMsg = $result['msg'] ?? '';

            debmes(
                'Ошибка изменения состояния устройства: ' . $errorCode . '. ' .
                'Устройство: "' . $deviceTitle . '" (ID: ' . $device_id . '), ' .
                'DPS: ' . $dps_name . ', ' .
                'Значение: ' . $value . ', ' .
                'Отправленный пакет: ' . $dps . '. ' .
                ($errorMsg ? 'Сообщение API: ' . $errorMsg : ''),
                'tuya'
            );
        }

        return $apiResult;
    }

    function TuyaRemoteMsg($dev_id, $value, $mode)
    {
        $token = $this->RefreshToken();
        $sURL = 'https://px1.tuyaeu.com/homeassistant/skill';

        $header = [
            'name' => $mode,
            'namespace' => 'control',
            'payloadVersion' => 1,
        ];
        $payload['value'] = $value;
        $payload['accessToken'] = $token;
        $payload['devId'] = $dev_id;

        $data = [
            'header' => $header,
            'payload' => $payload,
        ];


        $aHTTP = array(
            'http' =>
                array(
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($data, JSON_FORCE_OBJECT)
                )
        );
        $context = stream_context_create($aHTTP);
        $contents = file_get_contents($sURL, false, $context);
        $result = json_decode($contents);
        return $result;
    }

    function RGB_to_Tuya($RGB, $color_v2 = false)
    {
        $R = hexdec(substr($RGB, 0, 2));
        $G = hexdec(substr($RGB, 2, 2));
        $B = hexdec(substr($RGB, 4, 2));

        $HSL = array();

        $var_R = ($R / 255);
        $var_G = ($G / 255);
        $var_B = ($B / 255);

        $var_Min = min($var_R, $var_G, $var_B);
        $var_Max = max($var_R, $var_G, $var_B);
        $del_Max = $var_Max - $var_Min;

        $V = $var_Max;

        if ($del_Max == 0) {
            $H = 0;
            $S = 0;
        } else {
            $S = $del_Max / $var_Max;

            $del_R = ((($var_Max - $var_R) / 6) + ($del_Max / 2)) / $del_Max;
            $del_G = ((($var_Max - $var_G) / 6) + ($del_Max / 2)) / $del_Max;
            $del_B = ((($var_Max - $var_B) / 6) + ($del_Max / 2)) / $del_Max;

            if ($var_R == $var_Max) $H = $del_B - $del_G;
            else if ($var_G == $var_Max) $H = (1 / 3) + $del_R - $del_B;
            else if ($var_B == $var_Max) $H = (2 / 3) + $del_G - $del_R;

            if ($H < 0) $H++;
            if ($H > 1) $H--;
        }


        if (!$color_v2) {
            $HSL['H'] = dechex((int)($H * 360));
            $HSL['S'] = dechex((int)($S * 255));
            $HSL['V'] = dechex((int)($V * 255));

            $Tuya_Color = $RGB . '00';
            if (strlen($HSL['H']) == 1) {
                $Tuya_Color .= '0' . $HSL['H'];
            } else {
                $Tuya_Color .= $HSL['H'];
            }

            if (strlen($HSL['S']) == 1) {
                $Tuya_Color .= '0' . $HSL['S'];
            } else {
                $Tuya_Color .= $HSL['S'];
            }

            if (strlen($HSL['V']) == 1) {
                $Tuya_Color .= '0' . $HSL['V'];
            } else {
                $Tuya_Color .= $HSL['V'];
            }

            return $Tuya_Color;
        } else {
            $HSL['H'] = dechex((int)($H * 360));
            $HSL['S'] = dechex((int)($S * 1000));
            $HSL['V'] = dechex((int)($V * 1000));

            return str_pad($HSL['H'], 4, '0', STR_PAD_LEFT) . str_pad($HSL['S'], 4, '0', STR_PAD_LEFT) . str_pad($HSL['V'], 4, '0', STR_PAD_LEFT);

        }
    }

    function Tuya_to_RGB($tuya_color)
    {
        if (strlen($tuya_color) > 12) {
            return substr($tuya_color, 0, 6);
        } else {
            $hue = hexdec(substr($tuya_color, 0, 4));
            $sat = hexdec(substr($tuya_color, 4, 4));
            $val = hexdec(substr($tuya_color, 8, 4));

            $sat = $sat / 10;
            $val = $val / 10;

            $rgb = array(0, 0, 0);
            //calc rgb for 100% SV, go +1 for BR-range
            for ($i = 0; $i < 4; $i++) {
                if (abs($hue - $i * 120) < 120) {
                    $distance = max(60, abs($hue - $i * 120));
                    $rgb[$i % 3] = 1 - (($distance - 60) / 60);
                }
            }
            //desaturate by increasing lower levels
            $max = max($rgb);
            $factor = 255 * ($val / 100);
            for ($i = 0; $i < 3; $i++) {
                //use distance between 0 && max (1) && multiply with value
                $rgb[$i] = round(($rgb[$i] + ($max - $rgb[$i]) * (1 - $sat / 100)) * $factor);
            }
            $rgb = sprintf('%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);

            return $rgb;
        }
    }

    function Tuya_IOT_Login()
    {
        $this->getConfig();

        $this->config['TUYA_ACCESS_TOKEN'] = '';
        $this->saveConfig();
        $this->getConfig();

        $result = $this->Tuya_IOT_GET('/v1.0/token?grant_type=1', True);

        if (empty($result->success) || empty($result->result->access_token)) {
            $clientId = $this->config['TUYA_CLIENT_ID'] ?? 'не указан';
            debmes('Не удалось войти в облако IoT: ' . ($result->msg ?? 'неизвестная ошибка') . '. Client ID: ' . $clientId . '. Проверьте ключи доступа на developer.tuya.com.', 'tuya', 'error');
            return;
        }

        $access_token = $result->result->access_token;
        $this->config['TUYA_ACCESS_TOKEN'] = $access_token;
        $this->config['TUYA_REFRESH_TOKEN'] = $result->result->refresh_token;
        $this->config['TUYA_TOKEN_EXPIRE_TIME'] = $result->result->expire_time + time();
        $this->config['TUYA_IOT_UID'] = $result->result->uid;

        $this->saveConfig();

        return;

        $password = hash('sha256', $this->config['TUYA_PASSWD']);
        $username = $this->config['TUYA_USERNAME'];

        $data = array(
            'username' => $username,
            'password' => $password
        );

        $url = '/v1.0/iot-03/users/login';
        $url = '/v1.0/token?grant_type=1';
        $token = $this->Tuya_IOT_POST($url, $data, true);
        $access_token = $token->result->access_token;
        $this->config['TUYA_ACCESS_TOKEN'] = $access_token;
        $this->config['TUYA_REFRESH_TOKEN'] = $token->result->refresh_token;
        $this->config['TUYA_TOKEN_EXPIRE_TIME'] = $token->result->expire_time + time();
        $this->config['TUYA_IOT_UID'] = $token->result->uid;

        $this->saveConfig();
        return $token;
    }

    function Tuya_IOT_Refresh()
    {
        $this->getConfig();
        $this->config['TUYA_ACCESS_TOKEN'] = '';
        $this->saveConfig();
        $refresh_token = (string)($this->config['TUYA_REFRESH_TOKEN'] ?? '');
        $url = '/v1.0/iot-03/users/token/' . $refresh_token;
        $url = '/v1.0/token/' . $refresh_token;

        $token = $this->Tuya_IOT_GET($url, '', true);

        if (empty($token->success) || empty($token->result->access_token)) {
            debmes('Не удалось обновить токен IoT: ' . ($token->msg ?? 'неизвестная ошибка') . '. Токен истёк, выполняется повторный вход.', 'tuya');
            $token = $this->Tuya_IOT_Login();
        } else {
            $this->config['TUYA_ACCESS_TOKEN'] = $token->result->access_token;
            $this->config['TUYA_REFRESH_TOKEN'] = $token->result->refresh_token;
            $this->config['TUYA_TOKEN_EXPIRE_TIME'] = $token->result->expire_time + time();
            $this->saveConfig();
        }
        return $token;
    }

    function Tuya_IOT_POST($url, $data, $token_managment = false)
    {
        $this->touchCycleHeartbeat();
        $base = 'https://openapi.tuyaeu.com';
        $this->getConfig();
        if (!$token_managment && (time() > ((int)$this->config['TUYA_TOKEN_EXPIRE_TIME'] - 60))) {
            $result = $this->Tuya_IOT_Refresh();
            $this->getConfig();
        }
        $client_id = $this->config['TUYA_CLIENT_ID'];
        $secret = $this->config['TUYA_CLIENT_SECRET'];
        $access_token = $this->config['TUYA_ACCESS_TOKEN'];

        // Без ключей запрос подписи бессмысленен: возвращаем понятную ошибку,
        // а не пустой ответ API и предупреждение о пустом HMAC.
        if (trim((string)$client_id) === '' || trim((string)$secret) === '') {
            return (object)['success' => false, 'msg' => 'Не заданы Client ID и Client Secret платформы IoT'];
        }

        $data = json_encode($data);
        $sha256 = hash('sha256', $data);
        $stringToSign = 'POST' . "\n" . $sha256 . "\n" . "\n" . $url;

        $t = round(microtime(true) * 1000, 0);

        $sign = $client_id;
        if (!$token_managment) {
            $sign .= $access_token;
        }
        $sign .= $t . $stringToSign;
        $sign = hash_hmac('sha256', $sign, $secret);
        $sign = strtoupper($sign);

        $headers = ['client_id: ' . $client_id,
            'access_token: ' . $access_token,
            'sign: ' . $sign,
            't: ' . $t,
            'sign_method: HMAC-SHA256',
            "Content-Type: application/json"
        ];

        $aHTTP = array(
            'http' =>
                array(
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $data,
                    'timeout' => self::HTTP_TIMEOUT,
                )
        );


        $context = stream_context_create($aHTTP);
        $contents = @file_get_contents($base . $url, false, $context);
        if ($contents === false) {
            debmes('Ошибка сети при вызове Tuya IoT API (POST ' . $url . ')', 'tuya');
            return (object)['success' => false, 'msg' => 'Ошибка сети: не удалось подключиться к openapi.tuyaeu.com'];
        }
        $result = json_decode($contents);
        if ($result === null) {
            debmes('Ошибка парсинга ответа Tuya IoT API (POST ' . $url . '): ' . substr($contents, 0, 200), 'tuya');
            return (object)['success' => false, 'msg' => 'Ошибка парсинга ответа API'];
        }
        return $result;

    }

    function Tuya_IOT_GET($url, $token_managment = false)
    {
        $this->touchCycleHeartbeat();
        $base = 'https://openapi.tuyaeu.com';
        $this->getConfig();

        $client_id = $this->config['TUYA_CLIENT_ID'];
        $secret = $this->config['TUYA_CLIENT_SECRET'];
        $access_token = $this->config['TUYA_ACCESS_TOKEN'];

        if (trim((string)$client_id) === '' || trim((string)$secret) === '') {
            return (object)['success' => false, 'msg' => 'Не заданы Client ID и Client Secret платформы IoT'];
        }

        $data = '';
        $sha256 = hash('sha256', $data);
        $stringToSign = 'GET' . "\n" . $sha256 . "\n" . "\n" . $url;

        $t = round(microtime(true) * 1000, 0);

        $sign = $client_id;
        if (!$token_managment) {
            $sign .= $access_token;
        }
        $sign .= $t . $stringToSign;
        $sign = hash_hmac('sha256', $sign, $secret);
        $sign = strtoupper($sign);

        $headers = ['client_id: ' . $client_id,
            'access_token: ' . $access_token,
            'sign: ' . $sign,
            't: ' . $t,
            'sign_method: HMAC-SHA256',
            "Content-Type: application/json"
        ];

        $aHTTP = array(
            'http' =>
                array(
                    'method' => 'GET',
                    'header' => $headers,
                    'content' => $data,
                    'timeout' => self::HTTP_TIMEOUT,
                )
        );


        $context = stream_context_create($aHTTP);
        $contents = @file_get_contents($base . $url, false, $context);
        if ($contents === false) {
            debmes('Ошибка сети при вызове Tuya IoT API (GET ' . $url . ')', 'tuya');
            return (object)['success' => false, 'msg' => 'Ошибка сети: не удалось подключиться к openapi.tuyaeu.com'];
        }
        $result = json_decode($contents);
        if ($result === null) {
            debmes('Ошибка парсинга ответа Tuya IoT API (GET ' . $url . '): ' . substr($contents, 0, 200), 'tuya');
            return (object)['success' => false, 'msg' => 'Ошибка парсинга ответа API'];
        }
        return $result;


    }


    function getPendingValueCacheKey($command_id)
    {
        return 'tuya_pending_value_' . (int)$command_id;
    }

    function setPendingValue($command_id, $value, $ttl = 15)
    {
        if (!$command_id) {
            return;
        }
        saveToCache($this->getPendingValueCacheKey($command_id), (time() + (int)$ttl) . '|' . (string)$value);
    }

    function shouldSkipPendingValue($command_id, $value)
    {
        if (!$command_id) {
            return false;
        }

        $key = $this->getPendingValueCacheKey($command_id);
        $pending = checkFromCache($key);
        if ($pending === false || $pending === '') {
            return false;
        }

        $parts = explode('|', $pending, 2);
        $expires = (int)$parts[0];
        $expected = isset($parts[1]) ? $parts[1] : '';

        if ($expires < time()) {
            deleteFromCache($key);
            return false;
        }

        if ((string)$value === (string)$expected) {
            deleteFromCache($key);
            return false;
        }

        return true;
    }

    function processCommand($device_id, $command, $value, $params = 0, $checkOld = true)
    {

        $cmd_rec = SQLSelectOne("SELECT * FROM tucommands WHERE DEVICE_ID=" . (int)$device_id . " AND TITLE LIKE '" . DBSafe($command) . "'");

        if (!$cmd_rec['ID']) {
            $device = SQLSelectOne("SELECT * FROM tudevices WHERE ID=" . (int)$device_id);
            $cmd_rec = array();
            $cmd_rec['TITLE'] = $command;
            $cmd_rec['DEVICE_ID'] = $device_id;
            if ($device['TYPE'] == 'switch') {
                if ($command == '4') {
                    $cmd_rec['ALIAS'] = 'mA';
                } elseif ($command == '5') {
                    $cmd_rec['ALIAS'] = 'W';
                    $cmd_rec['DIVIDEDBY10'] = 1;
                } elseif ($command == '6') {
                    $cmd_rec['ALIAS'] = 'V';
                    $cmd_rec['DIVIDEDBY10'] = 1;
                } elseif ($command == '18') {
                    $cmd_rec['ALIAS'] = 'mA';
                } elseif ($command == '19') {
                    $cmd_rec['ALIAS'] = 'W';
                    $cmd_rec['DIVIDEDBY10'] = 1;
                } elseif ($command == '20') {
                    $cmd_rec['ALIAS'] = 'V';
                    $cmd_rec['DIVIDEDBY10'] = 1;
                }
            } elseif ($device['TYPE'] == 'climate') {
                if ($command == 'current_temperature') {
                    $cmd_rec['DIVIDEDBY2'] = 1;
                } elseif ($command == '3') {
                    $cmd_rec['ALIAS'] = 'current_temperature';
                    $cmd_rec['DIVIDEDBY2'] = 1;
                } elseif ($command == '2') {
                    $cmd_rec['ALIAS'] = 'temperature';
                    $cmd_rec['DIVIDEDBY2'] = 1;
                } elseif ($command == '102') {
                    $cmd_rec['DIVIDEDBY2'] = 1;
                }
            }


            $cmd_rec['ID'] = SQLInsert('tucommands', $cmd_rec);
        }

        // Новая команда создана без этих полей: читаем их безопасно, иначе
        // PHP 8 пишет предупреждение в журнал на каждый первый пакет.
        $scale = (int)($cmd_rec['VALUE_SCALE'] ?? 0);
        if ($scale === 0) {
            if (!empty($cmd_rec['DIVIDEDBY10'])) $value = $value / 10;
            if (!empty($cmd_rec['DIVIDEDBY2'])) $value = $value / 2;
            if (!empty($cmd_rec['DIVIDEDBY100'])) $value = $value / 100;
        } else {
            $value = $value / (10 ** $scale);
        }

        if (!empty($cmd_rec['COLOR_CONVERT'])) {
            $value = $this->Tuya_to_RGB($value);
        }

        if (!empty($cmd_rec['DECODE'])) {
            $value = base64_decode((string)$value);
        }


        if (!empty($cmd_rec['SPLIT'])) {
            $json = json_decode((string)$value);
            foreach ((array)$json as $k => $d) {
                if (is_bool($d)) {
                    $d = ($d) ? 1 : 0;
                }

                $this->processCommand($device_id, $k, $d);
            }
        }


        if (!empty($cmd_rec['POWER_METER'])) {

            $v = base64_decode($value);


            $v6 = unpack("n", substr($v, 0, 2))[1] / 10;
            $a6 = unpack("N", hex2bin('00') . substr($v, 2, 3))[1] / 1000;
            $w6 = unpack("N", hex2bin('00') . substr($v, 5, 3))[1];

            $this->processCommand($device_id, $command . '_v', $v6, $params, $checkOld);
            $this->processCommand($device_id, $command . '_a', $a6, $params, $checkOld);
            $this->processCommand($device_id, $command . '_w', $w6, $params, $checkOld);

        }

        if (gettype($value) == 'string' && strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }


        if (is_null($value)) $value = '';

        $linked_value = $value;
        if (!empty($cmd_rec['COLOR_CONVERT']) && is_string($linked_value)) {
            $linked_value = substr($linked_value, 0, 6);
        }

        if (!empty($cmd_rec['REPLACE_LIST']) && is_string($linked_value)) {
            $list = explode(',', $cmd_rec['REPLACE_LIST']);
            foreach ($list as $pair) {
                $pair = trim($pair);
                $parts = explode('=', $pair);
                if (count($parts) >= 2 && $linked_value == trim($parts[0])) {
                    $linked_value = trim($parts[1]);
                    break;
                }
            }
        }

        if ($this->shouldSkipPendingValue($cmd_rec['ID'], $linked_value)) {
            return;
        }


        $old_rec = SQLSelectOne('SELECT * FROM tuvalues WHERE ID=' . $cmd_rec['ID'] . ';');

        if ($old_rec) {

            $old_value = $old_rec['VALUE'];
            if (is_null($old_value)) $old_value = '';

            $old_rec['VALUE'] = $linked_value;
            $old_rec['UPDATED'] = date('Y-m-d H:i:s');

            SQLUpdate('tuvalues', $old_rec);
            if ($checkOld && $old_value == $linked_value) return;

        } else {
            $old_rec = array();
            $old_rec['ID'] = $cmd_rec['ID'];
            $old_rec['VALUE'] = $linked_value;
            $old_rec['UPDATED'] = date('Y-m-d H:i:s');

            SQLInsert('tuvalues', $old_rec);
        }


        //$cmd_rec['VALUE'] = $value;
        //$cmd_rec['UPDATED'] = date('Y-m-d H:i:s');
        //SQLUpdate('tucommands', $cmd_rec);

        if ($command == 'state' || $command == 'switch_1' || $command == 'power' || $command == 'Power' || $command == 'switch_on') processSubscriptions('TUSTATUS', array('FIELD' => 'STATE', 'VALUE' => $value, 'ID' => $device_id));
        if ($command == 'online') processSubscriptions('TUSTATUS', array('FIELD' => 'ONLINE', 'VALUE' => $value, 'ID' => $device_id));

        if (!empty($cmd_rec['LINKED_OBJECT']) && !empty($cmd_rec['LINKED_PROPERTY']) && $value !== null) {
            setGlobal($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_PROPERTY'], $linked_value, array($this->name => 1), $this->name);
        }

        if (!empty($cmd_rec['LINKED_OBJECT']) && !empty($cmd_rec['LINKED_METHOD']) && $value !== null) {
            if (!is_array($params)) {
                $params = array();
            }
            $params['VALUE'] = $linked_value;
            $params['PROP'] = $cmd_rec['ALIAS'];
            callMethodSafe($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_METHOD'], $params);
        }

    }


    function propertySetHandle($object, $property, $value)
    {

        $properties = SQLSelect("SELECT tucommands.*, tudevices.TUYA_VER, tudevices.DEV_ID,tudevices.CONTROL,tudevices.STATUS, tudevices.LOCAL_KEY,tudevices.DEV_IP,tudevices.TYPE,tudevices.MESH_ID,tudevices.GID_ID,tudevices.MAC,tudevices.UUID FROM tucommands LEFT JOIN tudevices ON tudevices.ID=tucommands.DEVICE_ID WHERE tucommands.LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND tucommands.LINKED_PROPERTY LIKE '" . DBSafe($property) . "'");

        if ($properties) {
            $linked_value = $value;

            if (!empty($properties[0]['REPLACE_LIST'])) {
                $list = explode(',', $properties[0]['REPLACE_LIST']);
                foreach ($list as $pair) {
                    $parts = explode('=', trim($pair), 2);
                    if (count($parts) === 2 && $value == $parts[1]) {
                        $value = $parts[0];
                        break;
                    }
                }
            }
            $dps_name = $properties[0]['TITLE'];

            if ($properties[0]['COLOR_CONVERT']) {
                $value = $this->RGB_to_Tuya($value, $properties[0]['COLOR_V2']);
                //debmes('New color value:' . $value);
            }

            if ($properties[0]['DIVIDEDBY2']) {
                $value = $value * 2;
            }

            if ($properties[0]['DIVIDEDBY10']) {
                $value = $value * 10;
            }

            if ($properties[0]['DIVIDEDBY100']) {
                $value = $value * 100;
            }

            if ($properties[0]['VALUE_SCALE'] > 0) {
                $value = $value * (10 ** $properties[0]['VALUE_SCALE']);
            }

            $this->setPendingValue($properties[0]['ID'], $linked_value);

            if (((strlen($properties[0]['LOCAL_KEY']) == 0 || strlen($properties[0]['DEV_IP']) == 0) && (strlen($properties[0]['MAC']) == 0 || strlen($properties[0]['MESH_ID']) == 0)) || $properties[0]['CONTROL'] == 0) {

                if ($dps_name == 'state') {
                    if ($properties[0]['CONTROL'] == 0) {
                        $this->Tuya_Web_DP($properties[0]['DEV_ID'], $value, '1', $properties[0]['GID_ID'], $properties[0]['MESH_ID']);
                    } else {
                        $this->TuyaRemoteMsg($properties[0]['DEV_ID'], $value, 'turnOnOff');
                    }
                } else if ($dps_name == 'brightness') {
                    $this->TuyaRemoteMsg($properties[0]['DEV_ID'], $value, 'brightnessSet');
                } else if ($dps_name == 'color_temp') {
                    $this->TuyaRemoteMsg($properties[0]['DEV_ID'], $value, 'colorTemperatureSet');
                } else if ($dps_name == 'color_mode') {
                    $this->TuyaRemoteMsg($properties[0]['DEV_ID'], $value, 'colorModeSet');
                } else if ($dps_name == 'temperature') {
                    $this->TuyaRemoteMsg($properties[0]['DEV_ID'], $value, 'temperatureSet');
                } else if ($properties[0]['CONTROL'] == 0) {
                    $this->Tuya_Web_DP($properties[0]['DEV_ID'], $value, $dps_name, $properties[0]['GID_ID'], $properties[0]['MESH_ID']);
                }
            } else {
                $mdev = strpos($properties[0]['DEV_ID'], '_');
                if ($mdev > 0) {
                    $dev_id = substr($properties[0]['DEV_ID'], 0, $mdev);
                    if ($properties[0]['TITLE'] == 'state') $dps_name = substr($properties[0]['DEV_ID'], $mdev + 1);
                } else {
                    if ($properties[0]['TITLE'] == 'state') $dps_name = '1';
                    $dev_id = $properties[0]['DEV_ID'];
                }

                if ($properties[0]['VALUE_TYPE'] == 'bool' || $properties[0]['TITLE'] == 'state') {
                    $dps = '{"' . $dps_name . '":' . (($value == 1) ? 'true' : 'false') . '}';
                } else if ($properties[0]['VALUE_TYPE'] == 'value') {
                    $dps = '{"' . $dps_name . '":' . $value . '}';
                } else {
                    $dps = '{"' . $dps_name . '":"' . $value . '"}';
                }

                if (strlen($properties[0]['MESH_ID']) == 0) {
                    //debmes('Tuya: dps=' .$dps);
                    $this->TuyaLocalMsg('SET', $dev_id, $properties[0]['LOCAL_KEY'], $properties[0]['DEV_IP'], $dps, '', $properties[0]['TUYA_VER']);
                } else {
                    $gw = SQLSelectOne("SELECT * FROM tudevices WHERE DEV_ID='" . DBSafe($properties[0]['MESH_ID']) . "'");
                    if (!$gw || empty($gw['LOCAL_KEY']) || empty($gw['DEV_IP'])) {
                        DebMes('Tuya: для устройства ' . $properties[0]['DEV_ID'] . ' не найден шлюз ' . $properties[0]['MESH_ID'] . ' — команда не отправлена.', 'tuya', 'error');
                        return;
                    }
                    if ($gw['TUYA_VER'] == '3.3') {
                        $cid = $properties[0]['MAC'];
                    } else {
                        $cid = $properties[0]['UUID'];
                        $dev_id = $gw["DEV_ID"];
                    }
                    $this->TuyaLocalMsg('SET', $dev_id, $gw['LOCAL_KEY'], $gw['DEV_IP'], $dps, $cid, $gw['TUYA_VER']);
                }
            }

            $rec = SQLSelectOne("SELECT * FROM tuvalues WHERE ID=" . (int)$properties[0]['ID'] . ';');
            if ($rec) {
                $rec['VALUE'] = $linked_value;
                $rec['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('tuvalues', $rec);
            } else {
                $rec = array();
                $rec['ID'] = $properties[0]['ID'];
                $rec['VALUE'] = $linked_value;
                $rec['UPDATED'] = date('Y-m-d H:i:s');
                SQLInsert('tuvalues', $rec);
            }

        }
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        $rec = SQLSelectOne("SHOW TABLES LIKE 'tudevices';");
        if ($rec) {
            $table = 'tudevices';
            $fields = SQLSelect("SHOW FIELDS FROM `$table`;");
            $fields = array_column($fields, 'Field');
            if (!in_array('CONTROL', $fields)) {
                SQLExec("ALTER TABLE tudevices ADD CONTROL int(10) unsigned NOT NULL DEFAULT 0;");
                SQLExec("ALTER TABLE tudevices ADD STATUS int(10) unsigned NOT NULL DEFAULT 0;");
                SQLExec("UPDATE tudevices SET CONTROL=1, STATUS=1 WHERE ONLY_LOCAL=1");
            }

        }

        $rec = SQLSelectOne("SHOW TABLES LIKE 'tucommands';");
        if ($rec) {
            $table = 'tucommands';
            $fields = SQLSelect("SHOW FIELDS FROM `$table`;");
            $fields = array_column($fields, 'Field');
            if (in_array('VALUE', $fields)) {
                SQLExec("ALTER TABLE tucommands DROP COLUMN VALUE;");
            }
            if (in_array('UPDATED', $fields)) {
                SQLExec("ALTER TABLE tucommands DROP COLUMN UPDATED;");
            }
        }

        $rec = SQLSelectOne("SHOW TABLES LIKE 'tuvalues';");
        if (!$rec) {
            $sql = "CREATE TABLE tuvalues (ID INT, VALUE VARCHAR(255), UPDATED datetime, INDEX USING HASH (ID)) ENGINE = MEMORY;";
            SQLExec($sql);
        }

        parent::install();

        // На свежей установке режимы ещё не настроены: неиспользуемые циклы
        // сразу отключаются, чтобы не занимать процесс и не писать ошибки.
        $this->reconcileCycles(true);
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        // Сначала гасим циклы: их скрипты исчезнут вместе с модулем.
        try {
            $manager = new \MajorDoMo\Core\Cycles\CycleManager(ROOT);
            foreach ($this->cycleRequirements() as $cycle => $needed) {
                $manager->disable($cycle);
            }
        } catch (\Throwable $e) {
            DebMes('Tuya: не удалось отключить циклы при удалении — ' . $e->getMessage(), 'tuya', 'error');
        }

        $req = SQLSelect("SELECT * FROM tucommands WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");

        foreach ($req as $prop) {
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
        }
        SQLExec('DROP TABLE IF EXISTS tudevices');
        SQLExec('DROP TABLE IF EXISTS tucommands');
        SQLExec('DROP TABLE IF EXISTS turange');
        SQLExec('DROP TABLE IF EXISTS tuircommand');
        SQLExec('DROP TABLE IF EXISTS tuvalues');

        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data = '')
    {

        $data = <<<EOD
 tudevices: ID int(10) unsigned NOT NULL auto_increment
 tudevices: TITLE varchar(100) NOT NULL DEFAULT ''
 tudevices: TYPE varchar(100) NOT NULL DEFAULT ''
 tudevices: DEV_ICON varchar(100) NOT NULL DEFAULT ''
 tudevices: DEV_ID varchar(255) NOT NULL DEFAULT ''
 tudevices: LOCAL_KEY varchar(255) NOT NULL DEFAULT ''
 tudevices: DEV_IP varchar(255) NOT NULL DEFAULT ''
 tudevices: BUSY boolean NOT NULL DEFAULT 0
 tudevices: UPDATED datetime
 tudevices: REMOTE_CONTROL boolean NOT NULL DEFAULT 0
 tudevices: ONLY_LOCAL boolean NOT NULL DEFAULT 0
 tudevices: PRODUCT_ID varchar(30) DEFAULT ''
 tudevices: GID_ID varchar(30) DEFAULT ''
 tudevices: REMOTE_CONTROL_2 boolean NOT NULL DEFAULT 0
 tudevices: MESH_ID varchar(30) DEFAULT ''
 tudevices: MAC varchar(30) DEFAULT ''
 tudevices: SEND12 boolean NOT NULL DEFAULT 0
 tudevices: FLAGS12 varchar(30) DEFAULT ''
 tudevices: VER_3_1 boolean NOT NULL DEFAULT 0
 tudevices: IR_FLAG boolean NOT NULL DEFAULT 0
 tudevices: CONTROL int(10) unsigned NOT NULL DEFAULT 0
 tudevices: STATUS int(10) unsigned NOT NULL DEFAULT 0
 tudevices: UUID varchar(30) NOT NULL DEFAULT ''
 tudevices: TUYA_VER varchar(5) DEFAULT '3.3'
 tudevices: DSP_FILLED boolean NOT NULL DEFAULT 0


 tucommands: ID int(10) unsigned NOT NULL auto_increment
 tucommands: TITLE varchar(100) NOT NULL DEFAULT ''
 tucommands: ALIAS varchar(255) NOT NULL DEFAULT ''
 tucommands: SDEVICE_TYPE varchar(255) NOT NULL DEFAULT ''
 tucommands: DEVICE_ID int(10) NOT NULL DEFAULT '0'
 tucommands: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 tucommands: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 tucommands: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 tucommands: DIVIDEDBY10 boolean NOT NULL DEFAULT 0
 tucommands: DIVIDEDBY2 boolean NOT NULL DEFAULT 0
 tucommands: DIVIDEDBY100 boolean DEFAULT 0
 tucommands: MODE varchar(10) DEFAULT ''
 tucommands: VALUE_TYPE varchar(10) DEFAULT ''
 tucommands: VALUE_MIN varchar(10) DEFAULT '0'
 tucommands: VALUE_MAX varchar(10) DEFAULT '0'
 tucommands: VALUE_SCALE int(10) DEFAULT 0
 tucommands: VALUE_UNIT varchar(10) DEFAULT ''
 tucommands: COLOR_CONVERT boolean DEFAULT 0
 tucommands: REPLACE_LIST varchar(255) DEFAULT ''
 tucommands: COLOR_V2 boolean DEFAULT 0
 tucommands: POWER_METER boolean DEFAULT 0
 tucommands: DECODE boolean DEFAULT 0
 tucommands: SPLIT boolean DEFAULT 0





 turange: ID int(10) unsigned NOT NULL auto_increment
 turange: COMMAND_ID int(10) unsigned NOT NULL
 turange: RANGE_VALUE varchar(10) NOT NULL DEFAULT ''
 turange: RANGE_DESCRIPTION varchar(50) NOT NULL DEFAULT ''


EOD;

        parent::dbInstall($data);
    }

    /**
     * Синхронизация удалённых устройств
     * Удаляет из локальной БД устройства, которых больше нет в облаке Tuya
     *
     * @access private
     */
    function syncDeletedDevices($gid)
    {
        $localDevices = (array)SQLSelect("SELECT DEV_ID FROM tudevices WHERE GID_ID='" . DBSafe($gid) . "' AND IR_FLAG=0 AND TYPE!='scene'");
        $localIds = array_column($localDevices, 'DEV_ID');
        if (empty($localIds)) return;

        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.my.group.device.list',
            'gid' => $gid,
            'requiresSID' => 1]);
        $result = json_decode($apiResult, true);
        if (empty($result['result'])) return;

        $cloudIds = array_column($result['result'], 'devId');
        $deletedIds = array_diff($localIds, $cloudIds);

        foreach ($deletedIds as $devId) {
            $rec = SQLSelectOne("SELECT ID FROM tudevices WHERE DEV_ID='" . DBSafe($devId) . "'");
            if ($rec) {
                SQLExec("DELETE FROM tuvalues WHERE ID IN (SELECT ID FROM tucommands WHERE DEVICE_ID=" . (int)$rec['ID'] . ")");
                SQLExec("DELETE FROM tucommands WHERE DEVICE_ID=" . (int)$rec['ID']);
                SQLExec("DELETE FROM tudevices WHERE ID=" . (int)$rec['ID']);
                debmes('Устройство ' . $devId . ' удалено из локальной БД (отсутствует в облаке Tuya)', 'tuya');
            }
        }
    }

    /**
     * Обновление названий устройств из облака
     * Синхронизирует TITLE устройств при переименовании в приложении Tuya
     *
     * @access private
     */
    function syncDeviceTitles($gid)
    {
        $apiResult = $this->TuyaWebRequest(['action' => 'tuya.m.my.group.device.list',
            'gid' => $gid,
            'requiresSID' => 1]);
        $result = json_decode($apiResult, true);
        if (empty($result['result'])) return;

        foreach ($result['result'] as $device) {
            $rec = SQLSelectOne("SELECT ID, TITLE FROM tudevices WHERE DEV_ID='" . DBSafe($device['devId']) . "'");
            if ($rec && $rec['TITLE'] != $device['name']) {
                $oldTitle = $rec['TITLE'];
                $rec['TITLE'] = $device['name'];
                SQLUpdate('tudevices', $rec);
                debmes('Устройство переименовано: "' . $oldTitle . '" → "' . $device['name'] . '"', 'tuya');
            }
        }
    }
// --------------------------------------------------------------------
}
/*
*
*
*
*/
