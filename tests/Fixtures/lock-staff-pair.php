<?php

[, $first, $second, $holdMs] = $argv;

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

$lock = $pdo->prepare('SELECT id FROM staff WHERE id = ? FOR UPDATE');

try {
    $pdo->beginTransaction();
    $lock->execute([$first]);
    fwrite(STDOUT, "locked:{$first}\n");
    usleep((int) $holdMs * 1000);
    $lock->execute([$second]);
    $pdo->commit();
    fwrite(STDOUT, "ok\n");
} catch (PDOException $e) {
    fwrite(STDOUT, (($e->errorInfo[1] ?? 0) === 1213 ? 'deadlock' : 'error:'.$e->getMessage())."\n");
}
