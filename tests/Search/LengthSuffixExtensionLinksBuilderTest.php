<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\LengthSuffixExtensionLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\LengthSuffixExtensionLinksBuilder (D-044, palier 2) : maillage en entonnoir
 * "{N}-lettres/terminant/{1 lettre}" -> "{N}-lettres/terminant/{2 lettres}" -- lu depuis
 * list_counts (list_type 'length_suffix2', precalcule par scripts/build_explore_hub_counts.php),
 * jamais un GROUP BY sur `terms` au runtime. L'extension ajoute une lettre EN TETE du suffixe
 * (meme convention que App\Search\SuffixExtensionLinksBuilder, variante SANS longueur), verifie
 * explicitement ci-dessous. Verifie par force brute sur la vraie base (lecture seule).
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new LengthSuffixExtensionLinksBuilder($connection);

    // --- Depuis "10-lettres/terminant/G" : extension EN TETE du suffixe (contrairement au
    // --- prefixe), verifie par force brute contre COUNT(*) reel, meme longueur exacte. ---
    $from10G = $builder->build(10, 'G');
    Assert::same(1, $from10G->queryCount);
    Assert::true($from10G->links !== [], 'sanity check : des mots de 10 lettres terminant par G existent');

    foreach ($from10G->links as $link) {
        Assert::same(2, strlen($link['suffix']), 'extension d\'une seule lettre attendue');
        Assert::true(str_ends_with($link['suffix'], 'G'), 'toute extension doit se terminer par G');

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE ?');
        $stmt->execute(['%' . $link['suffix']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "10-lettres/terminant/{$link['suffix']} : verifie par force brute");
        Assert::same('/mots/10-lettres/terminant/' . strtolower($link['suffix']), $link['url']);
    }

    // --- Doublons de contenu (D-044, verification EXTERNE faite AVANT application du lot SEO). ---
    $reflection = new ReflectionClass(LengthSuffixExtensionLinksBuilder::class);
    $externalDuplicateKeys = $reflection->getConstant('EXTERNAL_DUPLICATE_KEYS');
    Assert::same(515, count($externalDuplicateKeys), 'exactement 515 doublons attendus (386 D-044, verification faite avant application du lot, + 129 D-047, balayage du 2026-08-31 post-D-045/D-046)');
    Assert::same(count($externalDuplicateKeys), count(array_unique($externalDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');

    $rawTotal10G = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE '%G'")->fetch()['c'];
    $excludedCount10G = 0;
    foreach ($externalDuplicateKeys as $key) {
        if (str_starts_with($key, '10:') && str_ends_with($key, 'G')) {
            $letters = substr($key, 3);
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE ?");
            $stmt->execute(['%' . $letters]);
            $excludedCount10G += (int) $stmt->fetch()['c'];
        }
    }
    $expectedTotal10G = $rawTotal10G - $excludedCount10G;
    Assert::same($expectedTotal10G, array_sum(array_column($from10G->links, 'count')), 'somme des extensions = total du parent (meme longueur) MOINS les extensions exclues (D-044)');

    // --- Cas limite : un suffixe dont la SEULE extension reelle est exclue (D-044) doit produire
    // --- une liste vide, jamais une erreur -- 10-lettres/terminant/B n'a qu'une seule extension
    // --- reelle ("MB"), elle-meme un doublon de contenu exact avec /mots/terminant/mb. ---
    Assert::true(in_array('10:MB', $externalDuplicateKeys, true), 'sanity check : 10:MB fait bien partie de la liste figee');
    $rawCount10MB = (int) $pdo->query("SELECT count FROM list_counts WHERE list_type = 'length_suffix2' AND list_key = '10:MB'")->fetch()['count'];
    Assert::true($rawCount10MB > 0, 'sanity check : 10:MB existe bien dans list_counts (precalcul brut inchange, seule la sortie du builder est filtree)');
    $from10B = $builder->build(10, 'B');
    Assert::same([], $from10B->links, 'seule extension reelle exclue (D-044) -- liste vide attendue, pas d\'erreur');

    // --- Bornes explicites : un suffixe de 2 lettres est deja la derniere extension ouverte par
    // --- ce palier -- aucune extension a 3 lettres, liste vide attendue, jamais une requete
    // --- inutile (queryCount = 0). Meme chose pour une entree vide. ---
    $from10GN = $builder->build(10, 'GN');
    Assert::same([], $from10GN->links);
    Assert::same(0, $from10GN->queryCount, 'aucune requete pour un suffixe deja a la longueur maximale ouverte (2 lettres)');

    $fromEmpty = $builder->build(10, '');
    Assert::same([], $fromEmpty->links);
    Assert::same(0, $fromEmpty->queryCount);

    // --- R5 (registre SEO, jamais de lien mort), tri alphabetique. ---
    foreach ($from10G->links as $link) {
        Assert::true($link['count'] > 0, 'aucune entree a 0 attendue');
    }
    for ($i = 1; $i < count($from10G->links); $i++) {
        Assert::true($from10G->links[$i - 1]['suffix'] < $from10G->links[$i]['suffix'], 'ordre alphabetique attendu');
    }
};
