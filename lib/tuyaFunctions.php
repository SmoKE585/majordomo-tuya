<?php
/**
 * Вспомогательные функции для работы с Tuya API
 */

// Подключаем основной класс модуля
include_once(DIR_MODULES . 'tuya/tuya.class.php');

/**
 * Получение статистики устройства за месяц
 */
function Tuya_Web_Stats($device_id, $dp_id = 17, $gw_id = '') {
   $tuya_module = new tuya();

   if ($gw_id == '') {
      $gw_id = $device_id;
   }

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'tuya.m.location.list',
                                          'requiresSID' => 1]);

   $result = json_decode($apiResult, true);
   $gid = $result['result'][0]['groupId'];

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'tuya.m.dp.stat.month.list',
                                          'gid' => $gid,
                                          'data' => ['devId' => $device_id,
                                                  'gwId' => $gw_id,
                                                  'dpId' => $dp_id,
                                                  'type' => 'sum'],
                                          'requiresSID' => 1]);

   $result = json_decode($apiResult, true);
   return $result['result'];
}

/**
 * Получение лога операций устройства
 */
function Tuya_Web_Log($device_id, $dp_id = 1, $gw_id = '', $limit = 50, $offset = 0) {
   $tuya_module = new tuya();

   if ($gw_id == '') {
      $gw_id = $device_id;
   }

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'tuya.m.location.list',
                                          'requiresSID' => 1]);

   $result = json_decode($apiResult, true);
   $gid = $result['result'][0]['groupId'];

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'tuya.m.smart.operate.all.log',
                                          'gid' => $gid,
                                          'data' => ['devId' => $device_id,
                                                  'dpIds' => $dp_id,
                                                  'gwId' => $gw_id,
                                                  'limit' => $limit,
                                                  'offset' => $offset,
                                                  'startTime' => '',
                                                  'endTime' => '',
                                                  'sortType' => ''],
                                          'requiresSID' => 1]);
   $result = json_decode($apiResult, true);
   return $result['result'];
}

/**
 * Получение лога дверного замка
 */
function Tuya_Door_Log($device_id, $dp_id = 1, $gw_id = '', $limit = 50, $offset = 0) {
   $tuya_module = new tuya();

   if ($gw_id == '') {
      $gw_id = $device_id;
   }

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'tuya.m.location.list',
                                          'requiresSID' => 1]);

   $result = json_decode($apiResult, true);
   $gid = $result['result'][0]['groupId'];

   $apiResult = $tuya_module->TuyaWebRequest(['action' => 'm.smart.scale.history.get.list',
                                          'gid' => $gid,
                                          'data' => ['devId' => $device_id,
                                                  'dpIds' => "",
                                                  'offset' => $offset,
                                                  'limit' => $limit
                                                  ],
                                          'requiresSID' => 1]);

   $result = json_decode($apiResult, true);
   return $result['result'];
}

/**
 * Получение имени пользователя дверного замка по ID
 */
function TuyaDoorUser($device_id, $id) {
   $tuya_module = new tuya();

   $gid = SQLSelectOne("SELECT GID_ID FROM tudevices WHERE DEV_ID='" . DBSafe($device_id) . "';");
   $gid = $gid['GID_ID'];
   $action = "tuya.m.scale.history.door.user.list";

   $apiResult = $tuya_module->TuyaWebRequest(['action' => $action,
                                          'gid' => $gid,
                                          'data' => [
                                                   'devId' => $device_id,
                                                   'gwId' => $device_id,
                                           ],
                                          'requiresSID' => 1], '1.0');
   $apiResult = json_decode($apiResult, true);

   foreach ($apiResult['result']['familyMember'] as $user) {
      foreach ($user['unlockIds'] as $auth_id) {
         if ($auth_id == $id) {
            return $user['userName'];
         }
      }
   }
   return 'Неизвестный пользователь';
}
