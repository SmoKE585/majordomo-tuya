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

if (!empty($res[0]['ID'])) {

   $total = count($res);
   for ($i = 0; $i < $total; $i++) {

      $commands = SQLSelect("SELECT tucommands.*, tuvalues.VALUE, tuvalues.UPDATED FROM tucommands LEFT JOIN tuvalues ON tucommands.ID=tuvalues.ID WHERE DEVICE_ID=" . $res[$i]['ID'] . " and TITLE!='state' AND TITLE!='report' ORDER BY TITLE");

      if (!empty($commands[0]['ID'])) {
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
            $value = (string)($commands[$ic]['VALUE'] ?? '');
            $unit  = (string)($commands[$ic]['VALUE_UNIT'] ?? '');

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
               $v = mb_strlen((string)$lc['value']) > 25 ? mb_substr((string)$lc['value'], 0, 25) . '…' : (string)$lc['value'];
               $unitHtml = $lc['unit'] ? ' <span class="unit">' . htmlspecialchars($lc['unit'], ENT_QUOTES, 'UTF-8') . '</span>' : '';
               $parts[] = '<span class="md-badge md-badge--outline" title="' . htmlspecialchars($lc['link'], ENT_QUOTES, 'UTF-8') . '"><i>' . htmlspecialchars($lc['alias'], ENT_QUOTES, 'UTF-8') . '</i> ' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . $unitHtml . '</span>';
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

$out['TOTAL_DEVICES'] = count($res);
$out['ONLINE_DEVICES'] = 0;
foreach ($res as $device) {
   if ((int)($device['ONLINE'] ?? 0) === 1) {
      $out['ONLINE_DEVICES']++;
   }
}
$out['OFFLINE_DEVICES'] = $out['TOTAL_DEVICES'] - $out['ONLINE_DEVICES'];

// Tuya names/values are untrusted, including strings used in data attributes.
// COMMANDS contains locally generated HTML with individually escaped values.
$escapeDisplay = function ($items) use (&$escapeDisplay) {
   foreach ($items as $key => $value) {
      if (is_array($value)) {
         $items[$key] = $escapeDisplay($value);
      } elseif ($key !== 'COMMANDS') {
         $items[$key] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }
   }
   return $items;
};
$out['RESULT'] = $escapeDisplay($res);
