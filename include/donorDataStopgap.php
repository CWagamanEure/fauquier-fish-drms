<?php
// This file provides the fake backend, once schema is set, change donor.php & donorProfile.php to SQL and remove this file.

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('initials')) {
  function initials($name) {
    $w = preg_split('/\s+/', trim($name));
    return strtoupper(substr($w[0], 0, 1) . (count($w) > 1 ? substr(end($w), 0, 1) : ''));
  }
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function stopgap_donors_base() {
  return [
    ['id'=>1,  'name'=>'Main St. Church',       'type'=>'Organization', 'contact'=>'office@mainstchurch.org', 'lifetime'=>4250,     'last_gift'=>'2026-10-03', 'status'=>'active'],
    ['id'=>2,  'name'=>'Jhon D. Rangel Duran',  'type'=>'Individual',   'contact'=>'(540) 555-0173',          'lifetime'=>11250.74, 'last_gift'=>'2026-10-03', 'status'=>'active'],
    ['id'=>3,  'name'=>'Oak Ridge Elementary',  'type'=>'Organization', 'contact'=>'pta@oakridge.edu',        'lifetime'=>800,      'last_gift'=>'2026-10-02', 'status'=>'active'],
    ['id'=>4,  'name'=>'Ravi Patel',            'type'=>'Individual',   'contact'=>'rpatel@email.com',        'lifetime'=>640,      'last_gift'=>'2026-10-02', 'status'=>'active'],
    ['id'=>5,  'name'=>'Warrenton Rotary',      'type'=>'Organization', 'contact'=>'(540) 555-0110',          'lifetime'=>9500,     'last_gift'=>'2026-10-01', 'status'=>'active'],
    ['id'=>6,  'name'=>'Lin Chen',              'type'=>'Individual',   'contact'=>'lchen@email.com',         'lifetime'=>75,       'last_gift'=>'2026-09-30', 'status'=>'new'],
    ['id'=>7,  'name'=>'Fauquier Thrift',       'type'=>'Organization', 'contact'=>'(540) 555-0164',          'lifetime'=>2500,     'last_gift'=>'2026-09-29', 'status'=>'active'],
    ['id'=>8,  'name'=>'Dana Brooks',           'type'=>'Individual',   'contact'=>'(540) 555-0129',          'lifetime'=>1050,     'last_gift'=>'2025-07-08', 'status'=>'lapsed'],
    ['id'=>9,  'name'=>'Maria Gonzalez',        'type'=>'Individual',   'contact'=>'mgonzalez@email.com',     'lifetime'=>320,      'last_gift'=>'2026-09-15', 'status'=>'active'],
    ['id'=>10, 'name'=>'Bealeton Hardware',     'type'=>'Organization', 'contact'=>'(540) 555-0188',          'lifetime'=>1800,     'last_gift'=>'2026-09-10', 'status'=>'active'],
    ['id'=>11, 'name'=>'Tom Whitaker',          'type'=>'Individual',   'contact'=>'twhitaker@email.com',     'lifetime'=>45,       'last_gift'=>'2026-09-28', 'status'=>'new'],
    ['id'=>12, 'name'=>'Carol Nguyen',          'type'=>'Individual',   'contact'=>'(540) 555-0142',          'lifetime'=>210,      'last_gift'=>'2025-03-14', 'status'=>'lapsed'],
  ];
}

function stopgap_profiles_base() {
  $blank = ['first'=>'','middle'=>'','last'=>'','org_name'=>'','email'=>'','phone'=>'','preferred'=>'',
            'street'=>'','city'=>'','state'=>'VA','zip'=>'','volunteer'=>0,'email_updates'=>1,
            'mail_receipts'=>0,'do_not_contact'=>0,'code'=>'','created'=>''];
  $rows = [
    1  => ['org_name'=>'Main St. Church',      'email'=>'office@mainstchurch.org', 'phone'=>'(540) 555-0100', 'preferred'=>'Email', 'street'=>'100 Main St',      'city'=>'Warrenton',    'zip'=>'20186', 'mail_receipts'=>1, 'created'=>'2019-03-14'],
    2  => ['first'=>'Jhon', 'middle'=>'D.', 'last'=>'Rangel Duran', 'email'=>'jrangel@email.com', 'phone'=>'(540) 555-0173', 'preferred'=>'Email', 'street'=>'123 Germanna Hwy', 'city'=>'Locust Grove', 'zip'=>'22508', 'volunteer'=>1, 'mail_receipts'=>1, 'code'=>'D-01842', 'created'=>'2021-03-04'],
    3  => ['org_name'=>'Oak Ridge Elementary', 'email'=>'pta@oakridge.edu',         'phone'=>'(540) 555-0120', 'preferred'=>'Email', 'street'=>'45 School Rd',      'city'=>'Bealeton',     'zip'=>'22712', 'created'=>'2022-09-12'],
    4  => ['first'=>'Ravi', 'last'=>'Patel',   'email'=>'rpatel@email.com',   'phone'=>'(540) 555-0147', 'preferred'=>'Email', 'street'=>'8 Orchard Ln',      'city'=>'Warrenton',    'zip'=>'20186', 'volunteer'=>1, 'created'=>'2024-02-10'],
    5  => ['org_name'=>'Warrenton Rotary',     'phone'=>'(540) 555-0110', 'preferred'=>'Phone', 'street'=>'300 Lee Hwy',       'city'=>'Warrenton',    'zip'=>'20186', 'created'=>'2020-06-01'],
    6  => ['first'=>'Lin',  'last'=>'Chen',    'email'=>'lchen@email.com',    'preferred'=>'Email', 'street'=>'27 Fairfax St',      'city'=>'Remington',    'zip'=>'22734', 'created'=>'2026-09-30'],
    7  => ['org_name'=>'Fauquier Thrift',      'phone'=>'(540) 555-0164', 'preferred'=>'Phone', 'street'=>'12 Commerce Dr',     'city'=>'Warrenton',    'zip'=>'20186', 'created'=>'2023-11-05'],
    8  => ['first'=>'Dana', 'last'=>'Brooks',  'phone'=>'(540) 555-0129', 'preferred'=>'Phone', 'street'=>'60 Ridge Rd',        'city'=>'Midland',      'zip'=>'22728', 'created'=>'2022-05-20'],
    9  => ['first'=>'Maria','last'=>'Gonzalez','email'=>'mgonzalez@email.com','phone'=>'(540) 555-0155', 'preferred'=>'Email', 'street'=>'91 Elm St',          'city'=>'Catlett',      'zip'=>'20119', 'created'=>'2025-01-15'],
    10 => ['org_name'=>'Bealeton Hardware',    'phone'=>'(540) 555-0188', 'preferred'=>'Phone', 'street'=>'5 Marsh Rd',         'city'=>'Bealeton',     'zip'=>'22712', 'created'=>'2023-04-18'],
    11 => ['first'=>'Tom',  'last'=>'Whitaker','email'=>'twhitaker@email.com','preferred'=>'Email', 'street'=>'14 Oak Ct',          'city'=>'Warrenton',    'zip'=>'20186', 'created'=>'2026-09-28'],
    12 => ['first'=>'Carol','last'=>'Nguyen',  'phone'=>'(540) 555-0142', 'preferred'=>'Phone', 'street'=>'73 River Rd',        'city'=>'The Plains',   'zip'=>'20198', 'created'=>'2023-03-14'],
  ];
  foreach ($rows as $id => $r) { $rows[$id] = array_merge($blank, $r); }
  return $rows;
}

function stopgap_donor_details() {
  return [
    1 => [
      'email'=>'office@mainstchurch.org', 'since'=>'2019',
      'cash'=>[
        ['date'=>'2026-10-03','amount'=>2500,'payment'=>'Check #2210','campaign'=>'Year-End Giving','receipt'=>'sent'],
        ['date'=>'2026-03-12','amount'=>1750,'payment'=>'Check #2150','campaign'=>'General Fund','receipt'=>'sent'],
      ],
      'items'=>[ ['date'=>'2025-11-20','qty'=>120,'item'=>'Canned vegetables','value'=>180.00,'campaign'=>'Thanksgiving Food Drive','receipt'=>'sent'] ],
    ],
    2 => [
      'email'=>'jrangel@email.com', 'phone'=>'(540) 555-0173', 'location'=>'Locust Grove, VA',
      'since'=>'2021', 'prefers'=>'Email', 'last_contact'=>'2026-09-28',
      'cash'=>[
        ['date'=>'2026-10-03','amount'=>10000.74,'payment'=>'Check #1042','campaign'=>'Year-End Giving','receipt'=>'sent'],
        ['date'=>'2026-08-01','amount'=>1000.00,'payment'=>'Online','campaign'=>'General Fund','receipt'=>'sent'],
        ['date'=>'2026-06-01','amount'=>250.00,'payment'=>'Dogecoin','campaign'=>'Thanksgiving Food Drive','receipt'=>'pending'],
      ],
      'items'=>[
        ['date'=>'2026-08-01','qty'=>800,'item'=>'Pumpkins','value'=>2400.09,'campaign'=>'','receipt'=>'sent'],
        ['date'=>'2025-11-27','qty'=>3,'item'=>'Turkeys','value'=>100.80,'campaign'=>'Thanksgiving Food Drive','receipt'=>'pending'],
      ],
      'vol'=>[
        ['date'=>'2026-10-03','hours'=>4, 'job'=>'Line Cook','campaign'=>'Year-End Giving'],
        ['date'=>'2026-08-01','hours'=>12,'job'=>'Handing out Food','campaign'=>'General Fund'],
        ['date'=>'2026-06-01','hours'=>5, 'job'=>'Unloading Food','campaign'=>'Thanksgiving Food Drive'],
        ['date'=>'2026-06-01','hours'=>4, 'job'=>'Giving Good Vibes','campaign'=>'Thanksgiving Food Drive'],
        ['date'=>'2026-05-10','hours'=>6, 'job'=>'Sorting Donations','campaign'=>''],
        ['date'=>'2026-04-12','hours'=>6, 'job'=>'Handing out Food','campaign'=>''],
        ['date'=>'2026-03-08','hours'=>5, 'job'=>'Unloading Food','campaign'=>''],
      ],
      'comms'=>[
        ['date'=>'2025-12-30','type'=>'Email','subject'=>'[your] 2025 giving wrapped','campaign'=>'End of Year Thanks','status'=>'sent'],
      ],
    ],
    4 => [
      'email'=>'rpatel@email.com', 'since'=>'2024', 'prefers'=>'Email',
      'cash'=>[
        ['date'=>'2026-10-02','amount'=>240,'payment'=>'Online','campaign'=>'General Fund','receipt'=>'sent'],
        ['date'=>'2026-07-02','amount'=>200,'payment'=>'Online','campaign'=>'General Fund','receipt'=>'sent'],
        ['date'=>'2026-04-02','amount'=>200,'payment'=>'Online','campaign'=>'General Fund','receipt'=>'sent'],
      ],
      'vol'=>[
        ['date'=>'2026-09-14','hours'=>3,'job'=>'Handing out Food','campaign'=>''],
        ['date'=>'2026-08-10','hours'=>3,'job'=>'Unloading Food','campaign'=>''],
      ],
    ],
  ];
}

function stopgap_profile($id) {
  $base = stopgap_profiles_base()[$id] ?? ($_SESSION['stopgap_new'][$id] ?? null);
  if (!$base) return null;
  foreach (stopgap_donors_base() as $d) {
    if ($d['id'] === $id) { $base['type'] = $d['type']; break; }
  }
  if ($base['code'] === '') $base['code'] = sprintf('D-%05d', 1000 + $id);
  return array_merge($base, $_SESSION['stopgap_edits'][$id] ?? []);
}

function stopgap_display_name($p) {
  if ($p['type'] === 'Organization') return $p['org_name'];
  return trim(implode(' ', array_filter([$p['first'], $p['middle'], $p['last']])));
}

function stopgap_donors() {
  $edits    = $_SESSION['stopgap_edits']    ?? [];
  $inactive = $_SESSION['stopgap_inactive'] ?? [];
  $rows = [];
  foreach (stopgap_donors_base() as $d) {
    if (isset($edits[$d['id']])) {
      $p = stopgap_profile($d['id']);
      $d['type']    = $p['type'];
      $d['name']    = stopgap_display_name($p);
      $d['contact'] = $p['email'] ?: $p['phone'];
    }
    if (isset($inactive[$d['id']])) $d['inactive'] = true;
    $rows[] = $d;
  }
  foreach (array_keys($_SESSION['stopgap_new'] ?? []) as $nid) {
    $p = stopgap_profile($nid);
    $d = ['id'=>$nid, 'name'=>stopgap_display_name($p), 'type'=>$p['type'], 'contact'=>$p['email'] ?: $p['phone'],
          'lifetime'=>0, 'last_gift'=>'', 'status'=>'new'];
    if (isset($inactive[$nid])) $d['inactive'] = true;
    $rows[] = $d;
  }
  return $rows;
}