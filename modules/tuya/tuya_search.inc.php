<?php

global $session;

if ($this->owner->name == 'panel') {
   $out['CONTROLPANEL'] = 1;
}

$qry = '1';

global $save_qry;

if ($save_qry) {
   $qry = $session->data['tudevices_qry'];
} else {
   $session->data['tudevices_qry'] = $qry;
}

global $tab;

if ($tab == 'scene') {
   $res = SQLSelect("SELECT * FROM tudevices WHERE TYPE='scene' ORDER BY DEV_ID");
} elseif ($tab == 'ir') {   
   $res = SQLSelect("SELECT * FROM tudevices WHERE IR_FLAG=1;");
   
   if ($res) {
      foreach ($res as &$pult) {
         $codes = SQLSelect("SELECT tuircommand.*, '".$pult['DEV_ID']."' as DEV_ID FROM tuircommand WHERE TITLE !='' AND DEVICE_ID=" . $pult['ID'] );

         if ($codes) {
            $pult['CODES'] = $codes;
         } else {
            $apiResult = $this->TuyaWebRequest(['action'=> 'tuya.m.location.list',
                                                'requiresSID'=> 1]);

            $result=json_decode($apiResult , true);
            $gid= $result['result'][0] ['groupId'];

            $action = "tuya.m.infrared.record.get";
            
            $device_id = $pult['DEV_ID'];
            $gw_id = $pult['MESH_ID'];

            $apiResult = $this->TuyaWebRequest(['action'=>$action,
                                                'gid'=>$gid,
                                                'data'=> ['devId'=> $device_id,
                                                         'gwId'=>  $gw_id,
                                                         'subDevId'=> $gw_id,
                                                         'vender'=>'3',
                                                 ],
                                                'requiresSID'=> 1]);
            $result=json_decode($apiResult , true);

            // RF Code

            
            if ($result['result']['exts'] == '{"study":6}') {
               $action = "tuya.m.infrared.learn.get";

               $gw_id = $device_id;

               $apiResult = $this->TuyaWebRequest(['action'=>$action,
                                                   'gid'=>$gid,
                                                   'data'=> ['devId'=> $device_id,
                                                            'gwId'=>  $gw_id,
                                                            'subDevId'=> $gw_id,
                                                            'vender'=>'20',
                                                    ],
                                                   'requiresSID'=> 1]);

               $result=json_decode($apiResult , true);
   
               if ($result['result']) {
                  foreach ($result['result'] as $code) {

                     $pulse = ($code['compressPulse']);
                  
                     $pulse = str_replace('"', '\"', $pulse); 
                     $pulse = substr($pulse, 0, strlen($pulse)-1); 
                     $pulse .= ',\"ver\":\"2\"}';

                     $new_code['DEVICE_ID'] = $pult['ID'];
                     $new_code['TITLE'] = $code['keyName'];
                     $new_code['CPULSE_ALT'] = $pulse;
                     $new_code['EXTS'] = '';
                     $new_code['CPULSE_ALT_FLAG'] =  0;
                     $new_code['RF_FLAG'] =  1;
                     

                     SQLInsert('tuircommand', $new_code);
                     $new_code['DEV_ID'] = $pult['DEV_ID'];
                     array_push($codes, $new_code); 
                     unset($new_code['DEV_ID']);                     

                     
                  }
               
               }    
               
               $pult['CODES'] = $codes;    


            } else {

               $remote_id = $result['result']['remoteId'];
               $dev_type_id = $result['result']['devTypeId']; 

               $apiResult =  $this->TuyaWebRequest(['action'=> 'tuya.m.infrared.keydata.get',
                                                            'gid'=>$gid,
                                                            'data'=> ['devId'=> $device_id,
                                                            'devTypeId'=> $dev_type_id,
                                                            'gwId'=>  $gw_id,
                                                            'remoteId'=> $remote_id,
                                                            'vender'=>'3',
                                                            ],
                                                         'requiresSID'=> 1], '2.0');
               $result=json_decode($apiResult , true);
               
               $codes = array();
               
               if ($result['result']) {

                  foreach ($result['result']['compressPulseList'] as $code) {
                     $new_code['DEVICE_ID'] = $pult['ID'];
                     $new_code['TITLE'] = $code['keyName'];
                     $new_code['COMPRESSPULSE'] = $code['compressPulse'];
                     $exts = $code['exts'];
                     $exts = str_replace("\\","",$exts);
                     $exts = json_decode($exts , true);
                     $new_code['EXTS'] = $exts['99999'];
                     $new_code['CPULSE_ALT_FLAG'] =  0;
                     $new_code['RF_FLAG'] =  0;                     
                     
                     SQLInsert('tuircommand', $new_code);
                     $new_code['DEV_ID'] = $pult['DEV_ID'];
                     array_push($codes, $new_code); 
                     unset($new_code['DEV_ID']);
                  }
               
               }

               $action = "tuya.m.infrared.learn.get";

               $apiResult = $this->TuyaWebRequest(['action'=>$action,
                                          'gid'=>$gid,
                                          'data'=> ['devId'=> $gw_id,
                                                   'gwId'=>  $gw_id,
                                                   'subDevId'=> $device_id,
                                                   'vender'=>'3',
                                                   ],
                                          'requiresSID'=> 1]);

               $result=json_decode($apiResult , true);
               
               $codes = array();
               
               if ($result['result']) {

                  foreach ($result['result'] as $code) {
                     $new_code['DEVICE_ID'] = $pult['ID'];
                     $new_code['TITLE'] = $code['keyName'];
                     $new_code['CPULSE_ALT'] =  base64_encode(hex2bin($code['compressPulse']));
                     $new_code['CPULSE_ALT_FLAG'] =  1;
                     $new_code['RF_FLAG'] =  0;                     
                     
                     SQLInsert('tuircommand', $new_code);
                     $new_code['DEV_ID'] = $pult['DEV_ID'];
                     array_push($codes, $new_code); 
                     unset($new_code['DEV_ID']);
                  }
               
               }
               
               $pult['CODES'] = $codes;     
            
           }
         }   
      }      
   }

} else {
   if (!$qry) $qry = '';

   // Сортировка: по умолчанию по названию A-Z, можно переопределить через $_GET['sortby'] и $_GET['sortdir']
   global $sortby;
   global $sortdir;
   $allowed_sorts = ['TITLE', 'TYPE', 'DEV_ID', 'ONLINE'];
   if (!isset($sortby) || !in_array($sortby, $allowed_sorts)) {
      $sortby = 'TITLE';
   }
   if (!isset($sortdir) || !in_array(strtolower($sortdir), ['asc', 'desc'])) {
      $sortdir = 'ASC';
   }
   $sortdir = strtoupper($sortdir);
   $next_dir = ($sortdir == 'ASC') ? 'DESC' : 'ASC';

   // Переменные для шаблона
   $out['SORT_COL'] = $sortby;           // 'TITLE' или 'ONLINE'
   $out['SORT_DIR'] = $sortdir;          // 'ASC' или 'DESC'
   $out['SORT_NEXT'] = $next_dir;        // следующий порядок при клике

   $sortby_sql = ($sortby == 'ONLINE') ? 'tudevices.TITLE ASC' : 'tudevices.' . $sortby . ' ' . $sortdir;

   if ($qry != '') {
      $qry .= " AND ";
   }
   $qry .= "INSTR(DEV_ID,'_')=0 AND TYPE !='scene' AND IR_FLAG=0 ";

   $res = SQLSelect("SELECT * FROM tudevices WHERE $qry ORDER BY $sortby_sql");
   $last_i = 0;

   if ($res[0]['ID']) {

      $total = count($res);
      for ($i = 0; $i < $total; $i++) {

         $commands = SQLSelect("SELECT tucommands.*, tuvalues.VALUE, tuvalues.UPDATED FROM tucommands LEFT JOIN tuvalues ON tucommands.ID=tuvalues.ID WHERE DEVICE_ID=" . $res[$i]['ID'] . " and TITLE!='state' AND TITLE!='report' ORDER BY TITLE");

         if ($commands[0]['ID']) {
            $totalc = count($commands);
            $sub_dev = array();
            $linked_commands = array();
            for ($ic = 0; $ic < $totalc; $ic++) {
               if ($commands[$ic]['TITLE'] == 'online') {
                  if (!isset($commands[$ic]['VALUE'])) $commands[$ic]['VALUE'] = 0;
                  $res[$i]['ONLINE'] = (int)$commands[$ic]['VALUE'];
                  continue;
               }

               // Определяем алиас (если есть — используем его, иначе числовой TITLE)
               $alias = ($commands[$ic]['ALIAS'] != '') ? $commands[$ic]['ALIAS'] : $commands[$ic]['TITLE'];
               $value = $commands[$ic]['VALUE'] ?? '';
               $unit  = $commands[$ic]['VALUE_UNIT'] ?? '';

               // Индикаторы для значков включения/подсветки
               if ($alias == 'led_switch' || $alias == 'switch_led') {
                  $res[$i]['LAMP'] = (int)$value;
                  $res[$i]['IS_LAMP'] = 1;
               }

               if ($alias == 'power' || $alias == 'switch_1' || $alias == 'switch_on' || $alias == 'Power' || $alias == 'switch') {
                  $res[$i]['STATE'] = (int)$value;
                  $res[$i]['IS_STATE'] = 1;
               }

               if (substr($alias, 0, 7) == 'switch_') {
                   $switch_id = substr($alias, strpos($alias, '_') + 1);
                   $sub_name = SQLSelectOne("SELECT TITLE FROM tudevices WHERE DEV_ID = '" . DBSafe($res[$i]['DEV_ID'] . '_' . $switch_id) . "' ORDER BY DEV_ID");
                   $switch_name = $sub_name ? $sub_name['TITLE'] : $alias;
                   array_push($sub_dev, ['ID' => $switch_id, 'SWITCH_NAME' => $switch_name, 'SWITCH_STATE' => (int)$value]);
               }

               if ($alias == 'ir_code') {
                  $res[$i]['IS_STATE'] = 0;
                  $res[$i]['IS_LAMP'] = 0;
               }

               // Собираем только привязанные свойства для отображения
               if ($commands[$ic]['LINKED_OBJECT'] != '') {
                  $link = $commands[$ic]['LINKED_OBJECT'];
                  if ($commands[$ic]['LINKED_PROPERTY'] != '') {
                     $link .= '.' . $commands[$ic]['LINKED_PROPERTY'];
                  } elseif ($commands[$ic]['LINKED_METHOD'] != '') {
                     $link .= '.' . $commands[$ic]['LINKED_METHOD'];
                  }
                  $linked_commands[] = [
                     'alias' => $alias,
                     'value' => $value,
                     'unit'  => $unit,
                     'link'  => $link,
                  ];
               }
            }

            // Формируем строку только из привязанных свойств
            if (!empty($linked_commands)) {
               $parts = array();
               foreach ($linked_commands as $lc) {
                  $v = mb_strlen($lc['value']) > 30 ? mb_substr($lc['value'], 0, 30) . '…' : $lc['value'];
                  $parts[] = '<span class="text-nowrap me-2" title="' . htmlspecialchars($lc['link'], ENT_QUOTES, 'UTF-8') . '"><b>' . htmlspecialchars($lc['alias'], ENT_QUOTES, 'UTF-8') . '</b>: <i>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($lc['unit'], ENT_QUOTES, 'UTF-8') . '</i></span>';
               }
               $res[$i]['COMMANDS'] = implode('', $parts);
            }

            if (count($sub_dev) > 1) {
               $res[$i]['SUBDEV'] = $sub_dev;
               $res[$i]['IS_MULTI'] = 1;
               $res[$i]['IS_STATE'] = 0;
            }
          }


       }
   }

   // Сортировка по онлайн-статусу (после вычисления ONLINE)
   if ($sortby == 'ONLINE') {
      usort($res, function($a, $b) use ($sortdir) {
         $oa = (int)($a['ONLINE'] ?? 0);
         $ob = (int)($b['ONLINE'] ?? 0);
         if ($oa == $ob) return strcasecmp($a['TITLE'] ?? '', $b['TITLE'] ?? '');
         return ($sortdir == 'DESC') ? $ob - $oa : $oa - $ob;
      });
   }

}
$out['RESULT'] = $res;
