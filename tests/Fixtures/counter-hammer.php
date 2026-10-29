<?php

[, $staffId, $mode, $n] = $argv;

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$read = $pdo->prepare('SELECT upcoming_bookings_count FROM staff WHERE id = ?');
$write = $pdo->prepare('UPDATE staff SET upcoming_bookings_count = ? WHERE id = ?');
$atomic = $pdo->prepare('UPDATE staff SET upcoming_bookings_count = upcoming_bookings_count + 1 WHERE id = ?');

for ($i = 0; $i < (int) $n; $i++) {
    if ($mode === 'atomic') {
        $atomic->execute([$staffId]);
    } else {
        $read->execute([$staffId]);
        $value = (int) $read->fetchColumn();
        $write->execute([$value + 1, $staffId]);
    }
}
