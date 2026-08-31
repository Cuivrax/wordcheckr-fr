<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\LengthPrefixExtensionLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\LengthPrefixExtensionLinksBuilder (D-044, palier 2) : maillage en entonnoir
 * "{N}-lettres/commencant/{1 lettre}" -> "{N}-lettres/commencant/{2 lettres}" -- lu depuis
 * list_counts (list_type 'length_prefix2', precalcule par scripts/build_explore_hub_counts.php),
 * jamais un GROUP BY sur `terms` au runtime. Verifie par force brute sur la vraie base (lecture
 * seule), meme methodologie que PrefixExtensionLinksBuilderTest.php (variante SANS longueur).
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new LengthPrefixExtensionLinksBuilder($connection);

    // --- Depuis "10-lettres/commencant/A" : toutes les extensions a 2 lettres reelles doivent
    // --- apparaitre -- verifie par force brute contre COUNT(*) reel, meme longueur exacte. ---
    $from10A = $builder->build(10, 'A');
    Assert::same(1, $from10A->queryCount);
    Assert::true($from10A->links !== [], 'sanity check : des mots de 10 lettres commencant par A existent');

    foreach ($from10A->links as $link) {
        Assert::same(2, strlen($link['prefix']), 'extension d\'une seule lettre attendue');
        Assert::true(str_starts_with($link['prefix'], 'A'), 'toute extension doit commencer par A');

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE ?');
        $stmt->execute([$link['prefix'] . '%']);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "10-lettres/commencant/{$link['prefix']} : verifie par force brute");
        Assert::same('/mots/10-lettres/commencant/' . strtolower($link['prefix']), $link['url']);
    }

    // La somme des comptes des extensions a 2 lettres doit egaler le total exact de
    // "10-lettres/commencant/a" -- a longueur fixee, AUCUN mot n'est jamais "consomme" par la
    // page du prefixe lui-meme (contrairement a la variante sans longueur ou un mot peut etre
    // exactement egal au prefixe) : ici tous les mots de longueur 10 commencant par A ont
    // necessairement une 2e lettre. MOINS les extensions exclues (D-044, doublons de contenu
    // verifies avant application du lot SEO) presentes dans ce groupe precis.
    $reflection = new ReflectionClass(LengthPrefixExtensionLinksBuilder::class);
    $externalDuplicateKeys = $reflection->getConstant('EXTERNAL_DUPLICATE_KEYS');
    Assert::same(223, count($externalDuplicateKeys), 'exactement 223 doublons attendus (D-044, verification faite avant application du lot)');
    Assert::same(count($externalDuplicateKeys), count(array_unique($externalDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');

    $rawTotal10A = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE 'A%'")->fetch()['c'];
    $excludedCount10A = 0;
    foreach ($externalDuplicateKeys as $key) {
        if (str_starts_with($key, '10:A')) {
            $letters = substr($key, 3);
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM terms WHERE length = 10 AND normalized LIKE ?");
            $stmt->execute([$letters . '%']);
            $excludedCount10A += (int) $stmt->fetch()['c'];
        }
    }
    $expectedTotal10A = $rawTotal10A - $excludedCount10A;
    Assert::same($expectedTotal10A, array_sum(array_column($from10A->links, 'count')), 'somme des extensions = total du parent (meme longueur) MOINS les extensions exclues (D-044)');

    // --- Cas limite : un prefixe dont la SEULE extension reelle est exclue (D-044) doit produire
    // --- une liste vide, jamais une erreur -- 12-lettres/commencant/Q n'a qu'une seule extension
    // --- reelle ("QU"), elle-meme un doublon de contenu exact avec /mots/commencant/qu. ---
    Assert::true(in_array('12:QU', $externalDuplicateKeys, true), 'sanity check : 12:QU fait bien partie de la liste figee');
    $rawCount12QU = (int) $pdo->query("SELECT count FROM list_counts WHERE list_type = 'length_prefix2' AND list_key = '12:QU'")->fetch()['count'];
    Assert::true($rawCount12QU > 0, 'sanity check : 12:QU existe bien dans list_counts (precalcul brut inchange, seule la sortie du builder est filtree)');
    $from12Q = $builder->build(12, 'Q');
    Assert::same([], $from12Q->links, 'seule extension reelle exclue (D-044) -- liste vide attendue, pas d\'erreur');

    // --- Bornes explicites : un prefixe de 2 lettres est deja la derniere extension ouverte par
    // --- ce palier -- aucune extension a 3 lettres, liste vide attendue, jamais une requete
    // --- inutile (queryCount = 0). Meme chose pour une entree vide ou trop longue. ---
    $from10AB = $builder->build(10, 'AB');
    Assert::same([], $from10AB->links);
    Assert::same(0, $from10AB->queryCount, 'aucune requete pour un prefixe deja a la longueur maximale ouverte (2 lettres)');

    $fromEmpty = $builder->build(10, '');
    Assert::same([], $fromEmpty->links);
    Assert::same(0, $fromEmpty->queryCount);

    // --- Longueur sans aucun mot commencant par cette lettre (cas degenere plausible pour une
    // --- lettre rare a une longueur donnee) : liste vide, pas d'erreur. ---
    $rare = $builder->build(15, 'W');
    Assert::true($rare->links === [] || array_sum(array_column($rare->links, 'count')) > 0, 'liste vide ou comptes reels, jamais un lien mort (R5)');

    // --- R5 (registre SEO, jamais de lien mort) : aucune entree a 0. ---
    foreach ($from10A->links as $link) {
        Assert::true($link['count'] > 0, 'aucune entree a 0 attendue');
    }

    // --- Tri alphabetique. ---
    for ($i = 1; $i < count($from10A->links); $i++) {
        Assert::true($from10A->links[$i - 1]['prefix'] < $from10A->links[$i]['prefix'], 'ordre alphabetique attendu');
    }
};
