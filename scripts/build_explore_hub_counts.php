<?php

declare(strict_types=1);

/**
 * Precalcule les 66 comptes de la page hub /mots (longueur x14, commencant x26, terminant
 * x26) dans storage/dictionary_fr.sqlite -- hors ligne uniquement, jamais au runtime (D-001,
 * meme principe que score/signature/reversed).
 *
 * Mesure qui justifie ce script : SELECT substr(normalized,1,1), COUNT(*) FROM terms GROUP BY
 * ... force un SCAN complet de l'index (aucun index sur l'expression substr()) et un TEMP
 * B-TREE pour le GROUP BY -- 245 ms et 215 ms mesures pour commencant/terminant sur les
 * 838 180 lignes reelles, soit ~500 ms cumules pour une seule page. Largement au-dessus du
 * budget TTFB p95 < 250 ms (CLAUDE.md), pour un total qui ne change qu'a la reconstruction de
 * la base. Precalcule une fois ici, lu par App\Search\ExploreHubBuilder en une requete
 * triviale (SELECT * FROM list_counts, 66 lignes, aucun GROUP BY, aucun scan).
 *
 * Idempotent : peut etre relance apres chaque reconstruction de storage/dictionary_fr.sqlite
 * (scripts/import_fr.py) sans effet de bord -- DROP + CREATE + INSERT en une transaction.
 *
 * Usage : php scripts/build_explore_hub_counts.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "scripts/build_explore_hub_counts.php ne s'execute qu'en CLI, hors ligne.\n");
    exit(1);
}

$root = dirname(__DIR__);
$dbPath = getenv('SCRABBLE_DICTIONARY_DB_PATH') ?: $root . '/storage/dictionary_fr.sqlite';

if (!is_file($dbPath)) {
    fwrite(STDERR, "dictionnaire introuvable : {$dbPath}\n");
    exit(1);
}

// Lecture-ecriture ASSUMEE ici (hors ligne uniquement) : le runtime PHP (app/Database/
// Connection.php) ouvre toujours ce meme fichier en SQLITE_OPEN_READONLY -- ce script ne
// s'execute jamais dans le flux d'une requete HTTP.
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// DDL identique a schema.sql (source canonique) -- ecart trouve et corrige lors de l'audit
// final (code-reviewer, constat I7) : cette recreation omettait le CHECK sur list_type,
// present dans schema.sql mais jamais applique a la table reellement livree.
$pdo->exec('DROP TABLE IF EXISTS list_counts');
$pdo->exec(
    'CREATE TABLE list_counts ('
    . "list_type TEXT NOT NULL CHECK (list_type IN ('length', 'start', 'end')), "
    . 'list_key TEXT NOT NULL, '
    . 'count INTEGER NOT NULL, '
    . 'PRIMARY KEY (list_type, list_key)'
    . ')'
);

$insert = $pdo->prepare('INSERT INTO list_counts (list_type, list_key, count) VALUES (?, ?, ?)');

$pdo->beginTransaction();

$total = 0;

$lengthStatement = $pdo->query('SELECT length, COUNT(*) n FROM terms GROUP BY length ORDER BY length');
foreach ($lengthStatement as $row) {
    $insert->execute(['length', (string) $row['length'], (int) $row['n']]);
    $total++;
}

$startStatement = $pdo->query("SELECT substr(normalized, 1, 1) c, COUNT(*) n FROM terms GROUP BY c ORDER BY c");
foreach ($startStatement as $row) {
    $insert->execute(['start', $row['c'], (int) $row['n']]);
    $total++;
}

$endStatement = $pdo->query("SELECT substr(reversed, 1, 1) c, COUNT(*) n FROM terms GROUP BY c ORDER BY c");
foreach ($endStatement as $row) {
    $insert->execute(['end', $row['c'], (int) $row['n']]);
    $total++;
}

$pdo->commit();

printf("list_counts : %d lignes (14 longueur + 26 commencant + 26 terminant attendues)\n", $total);
