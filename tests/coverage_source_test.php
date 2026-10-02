<?php

require __DIR__ . '/bootstrap.php';

$src = (string) @file_get_contents(OPM_ROOT . '/src/Coverage/DbCoverageSource.php');
check('source exists', $src !== '');
check('entity restriction applied', str_contains($src, 'getEntitiesRestrictCriteria('));
check('custom assets keep their system criteria', str_contains($src, 'getSystemSQLCriteria()'));
check('deleted and template assets excluded', str_contains($src, ".is_deleted'] = 0") && str_contains($src, ".is_template'] = 0"));
check('states filter only when states chosen', (bool) preg_match('/if \(\$states !== \[\].*?states_id/s', $src));
check('ips fetched in one query per itemtype', (bool) preg_match("/glpi_ipaddresses.*?'mainitemtype'\s*=>\s*\\\$itemtype.*?'mainitems_id'\s*=>\s*\\\$ids/s", $src));
check('assets without an IP are left out', str_contains($src, 'if (!isset($ips['));

check('active alarms grouped by severity', (bool) preg_match("/function alarmsBySeverity.*?'state' => 'active'.*?'GROUPBY'/s", $src));
check('open alarm tickets are entity-scoped and exclude solved/closed/deleted', (bool) preg_match("/function openAlarmTickets.*?glpi_tickets.*?getEntitiesRestrictCriteria\('glpi_tickets'.*?'glpi_tickets.is_deleted' => 0.*?\[5, 6\]/s", $src));

done();
