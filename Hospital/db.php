<?php
declare(strict_types=1);

function hospitalDatabase(): PDO
{
    $databasePath = getenv('HOSPITAL_DB_PATH') ?: '/var/lib/hospital/estoque.sqlite';
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar o diretório do banco de dados.');
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $schema = file_get_contents(__DIR__ . '/estoque_hospital.sql');
    if ($schema === false) {
        throw new RuntimeException('Não foi possível ler o esquema do banco de dados.');
    }
    $pdo->exec($schema);
    return $pdo;
}
