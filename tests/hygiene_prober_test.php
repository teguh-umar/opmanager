<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Hygiene/TcpProber.php';

use GlpiPlugin\Opmanager\Hygiene\TcpProber;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
check('test server started', $server !== false, (string) $errstr);
$port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

// A closed port on localhost: find one by binding and releasing.
$tmp    = stream_socket_server('tcp://127.0.0.1:0');
$closed = (int) substr((string) strrchr((string) stream_socket_get_name($tmp, false), ':'), 1);
fclose($tmp);

$p = new TcpProber(500);
$r = $p->probe('127.0.0.1', [$closed, $port]);
check('open port found after a closed one', ($r['open'] ?? null) === $port);
check('tried lists the ports up to the open one', ($r['tried'] ?? null) === [$closed, $port]);

$r = $p->probe('127.0.0.1', [$closed]);
check('closed port: open is null', is_array($r) && $r['open'] === null && $r['tried'] === [$closed]);

$t     = 0.0;
$clock = static function () use (&$t): float { return $t; };
$p     = new TcpProber(500, 10.0, $clock);
check('within budget: probes', $p->probe('127.0.0.1', [$port]) !== null);
$t = 11.0;
check('budget spent: null (Review Focus 1)', $p->probe('127.0.0.1', [$port]) === null);

fclose($server);
done();
