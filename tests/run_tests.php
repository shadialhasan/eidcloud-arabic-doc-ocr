<?php

declare(strict_types=1);

require_once __DIR__ . '/DocOcrTest.php';

$runner = new DocOcrTest();
$success = $runner->runAll();

exit($success ? 0 : 1);
