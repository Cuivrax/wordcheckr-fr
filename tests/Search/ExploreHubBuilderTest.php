<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\ExploreHubBuilder;
use Tests\Support\Assert;

/**
 * App\Search\ExploreHubBuilder (page hub /mots) : jamais teste avant ce lot (D-049). Verifie le
 * CORRECTIF de performance (requete non bornee -- SELECT * FROM list_counts, 324 915 lignes --
 * remplacee par une requete preparee bornee WHERE list_type IN (?,?,?,?) LIMIT, confirmee en
 * direct 338,8 ms -> quelques ms) ET la nouvelle grille byWith (D-049, 'avec_bare').
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new ExploreHubBuilder($connection);

    $hub = $builder->build();
    Assert::same(1, $hub->queryCount, 'une seule requete SQLite, meme apres l\'ajout de byWith (D-049)');

    // --- byLength : 14 longueurs reelles, chacune avec un compte > 0. ---
    Assert::same(14, count($hub->byLength), 'sanity check : 14 longueurs (D-010, plafond a 15 lettres)');
    foreach ($hub->byLength as $entry) {
        Assert::true($entry['count'] > 0, 'R5 : aucune entree a 0 attendue (byLength)');
        Assert::same('/mots/' . $entry['length'] . '-lettres', $entry['url']);
    }
    for ($i = 1; $i < count($hub->byLength); $i++) {
        Assert::true($hub->byLength[$i - 1]['length'] < $hub->byLength[$i]['length'], 'ordre croissant attendu (byLength)');
    }

    // --- byStart / byEnd : 26 lettres chacun. ---
    Assert::same(26, count($hub->byStart), 'sanity check : 26 lettres (byStart)');
    Assert::same(26, count($hub->byEnd), 'sanity check : 26 lettres (byEnd)');
    foreach ([$hub->byStart, $hub->byEnd] as $group) {
        foreach ($group as $entry) {
            Assert::true($entry['count'] > 0, 'R5 : aucune entree a 0 attendue (byStart/byEnd)');
        }
    }

    // --- byWith (D-049) : 26 lettres "avec" bare, chacune avec un compte > 0, verifie par force
    // --- brute contre `terms`. ---
    Assert::same(26, count($hub->byWith), 'sanity check : 26 lettres avec_bare reelles (D-049)');
    foreach ($hub->byWith as $entry) {
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE instr(normalized, ?) > 0');
        $stmt->execute([$entry['letter']]);
        $expected = (int) $stmt->fetch()['c'];

        Assert::same($expected, $entry['count'], "compte divergent pour la lettre {$entry['letter']} (byWith)");
        Assert::same('/mots/avec/' . strtolower($entry['letter']), $entry['url']);
        Assert::true($entry['count'] > 0, 'R5 : aucune entree a 0 attendue (byWith)');
    }
    for ($i = 1; $i < count($hub->byWith); $i++) {
        Assert::true($hub->byWith[$i - 1]['letter'] < $hub->byWith[$i]['letter'], 'ordre alphabetique attendu (byWith)');
    }

    // --- Aucune famille inattendue melangee (list_type IN bien borne a 4 valeurs). ---
    $total = count($hub->byLength) + count($hub->byStart) + count($hub->byEnd) + count($hub->byWith);
    Assert::same(92, $total, 'sanity check : 14 + 26 + 26 + 26 = 92 liens au total sur le hub');
};
