<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Hygiene/Classifier.php';
require OPM_ROOT . '/src/Hygiene/Cidr.php';

use GlpiPlugin\Opmanager\Hygiene\Cidr;
use GlpiPlugin\Opmanager\Hygiene\Classifier as C;

$u     = ['UnManaged', 'Device Not Monitored.'];
$stale = '2026-11-06 10:00:00';
$fresh = '2026-11-06 11:00:00';
$dev   = static fn (string $availability, string $match, ?string $seen = '2026-11-06 11:00:00'): array
    => ['availability' => $availability, 'match_state' => $match, 'last_seen' => $seen];

// --- availability normalisation (Review Focus 3) ---
check('unmanaged: case and spaces ignored', C::isUnmanaged(' unmanaged ', $u));
check('device not monitored: case ignored', C::isUnmanaged('Device not monitored.', $u));
check('Clear is monitored', !C::isUnmanaged('Clear', $u));
check('empty availability is not unmanaged', !C::isUnmanaged('', $u));

// --- classification table (spec §2) ---
check('A: monitored + unmatched', C::classify($dev('Clear', 'unmatched'), '', false, $stale, $u) === C::GLPI_MISSING);
check("A': monitored + review", C::classify($dev('Critical', 'review'), '', false, $stale, $u) === C::GLPI_REVIEW);
check('E: monitored + matched', C::classify($dev('Clear', 'matched'), '', false, $stale, $u) === C::OK);
check('B: unmanaged + dead', C::classify($dev('UnManaged', 'matched'), 'dead', false, $stale, $u) === C::NMS_DEAD);
check('C: unmanaged + unknown', C::classify($dev('UnManaged', 'unmatched'), 'unknown', false, $stale, $u) === C::NMS_UNKNOWN);
check('C: unmanaged + not yet checked', C::classify($dev('UnManaged', 'unmatched'), '', false, $stale, $u) === C::NMS_UNKNOWN);
check('D: unmanaged + alive (matched)', C::classify($dev('UnManaged', 'matched'), 'alive', false, $stale, $u) === C::UNMANAGED_ALIVE);
check('D: unmanaged + alive (unmatched)', C::classify($dev('Device Not Monitored.', 'unmatched'), 'alive', false, $stale, $u) === C::UNMANAGED_ALIVE);
check('X: excepted monitored-unmatched', C::classify($dev('Clear', 'unmatched'), '', true, $stale, $u) === C::EXCEPTED);
check('X: excepted unmanaged-dead', C::classify($dev('UnManaged', 'matched'), 'dead', true, $stale, $u) === C::EXCEPTED);
check('empty availability: none', C::classify($dev('', 'unmatched'), '', false, $stale, $u) === C::NONE);

// --- a device that left OpManager is never ticketed (Review Focus 2) ---
check('stale last_seen: none', C::classify($dev('Clear', 'unmatched', '2026-11-06 09:59:59'), '', false, $stale, $u) === C::NONE);
check('stale last_seen: none even when excepted', C::classify($dev('UnManaged', 'matched', '2026-11-06 09:59:59'), 'dead', true, $stale, $u) === C::NONE);
check('null last_seen: none', C::classify($dev('Clear', 'unmatched', null), '', false, $stale, $u) === C::NONE);
check('last_seen equal to the cutoff still counts', C::classify($dev('Clear', 'unmatched', $stale), '', false, $stale, $u) === C::GLPI_MISSING);

check('flow of each ticketed state', C::FLOW_OF === [C::GLPI_MISSING => 'glpi', C::GLPI_REVIEW => 'glpi', C::NMS_DEAD => 'nms', C::NMS_UNKNOWN => 'nms']);
check('labels cover the 8 states', array_keys(C::labels()) === [C::GLPI_MISSING, C::GLPI_REVIEW, C::NMS_DEAD, C::NMS_UNKNOWN, C::UNMANAGED_ALIVE, C::OK, C::EXCEPTED, C::NONE]);

// --- CIDR ---
check('ip inside /16', Cidr::contains('10.20.12.60', ['10.20.0.0/16']));
check('ip outside /16', !Cidr::contains('10.30.8.6', ['10.20.0.0/16']));
check('garbage entries ignored', Cidr::contains('10.0.0.1', ['garbage', '10.0.0.0/8']));
check('/32 matches exactly', Cidr::contains('10.0.0.1', ['10.0.0.1/32']));
check('/0 matches everything', Cidr::contains('192.168.50.4', ['0.0.0.0/0']));
check('invalid ip never matches', !Cidr::contains('not-an-ip', ['0.0.0.0/0']));
check('IPv6 ip never matches', !Cidr::contains('::1', ['0.0.0.0/0']));
check('prefix 33 invalid', !Cidr::isValid('10.0.0.0/33'));
check('missing prefix invalid', !Cidr::isValid('10.0.0.0'));
check('valid cidr', Cidr::isValid('192.168.0.0/24'));
check('spaces around a cidr accepted', Cidr::isValid(' 10.0.0.0/8 '));

done();
