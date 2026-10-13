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

function stopgap_donors() {
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

//sample details for some donors with default
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