<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\SuffixAvecTwoLettersLinksBuilder;
use Tests\Support\Assert;

/**
 * App\Search\SuffixAvecTwoLettersLinksBuilder (D-045, palier 2 de Family::
 * WORD_LIST_TERMINANT_WITH_LETTER, symetrique de App\Search\PrefixAvecTwoLettersLinksBuilder
 * cote suffixe) : depuis "terminant/{X}/avec/{Y}" (deja indexee, palier 1), liens vers chaque
 * variante "terminant/{X}/avec/{Y}/{Z}" qui a au moins un resultat -- lu depuis list_counts
 * (list_type 'end_with_pair'), jamais un calcul sur `terms` au runtime.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $pdo = $connection->pdo();
    $builder = new SuffixAvecTwoLettersLinksBuilder($connection);

    // --- Cas representatif verifie par force brute : terminant/A/avec/B. ---
    $linksAB = $builder->build('A', 'B');
    Assert::same(1, $linksAB->queryCount);
    Assert::true($linksAB->links !== [], 'sanity check : des mots terminant par A avec B et une 3e lettre existent');

    foreach ($linksAB->links as $link) {
        Assert::true(!in_array($link['letter'], ['A', 'B'], true), 'la lettre produite ne doit jamais etre le suffixe ni la lettre source');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM terms WHERE normalized LIKE '%A' AND instr(normalized, 'B') > 0 AND instr(normalized, ?) > 0"
        );
        $stmt->execute([$link['letter']]);
        Assert::same((int) $stmt->fetch()['c'], $link['count'], "A+B+{$link['letter']} : verifie par force brute");

        $expectedLetters = ['B', $link['letter']];
        sort($expectedLetters);
        Assert::same('/mots/terminant/a/avec/' . strtolower($expectedLetters[0]) . '/' . strtolower($expectedLetters[1]), $link['url']);
        Assert::true($link['count'] > 0, 'R5 : aucune entree a 0 attendue');
    }

    for ($i = 1; $i < count($linksAB->links); $i++) {
        Assert::true($linksAB->links[$i - 1]['letter'] < $linksAB->links[$i]['letter'], 'ordre alphabetique attendu');
    }

    // --- Reflection : les deux listes figees ont les comptes reels calcules pour D-045. ---
    $reflection = new ReflectionClass(SuffixAvecTwoLettersLinksBuilder::class);
    $duplicateParentKeys = $reflection->getConstant('DUPLICATE_PARENT_KEYS');
    $siblingDuplicateKeys = $reflection->getConstant('SIBLING_DUPLICATE_KEYS');
    Assert::same(82, count($duplicateParentKeys), 'exactement 82 doublons parent attendus (D-045)');
    Assert::same(283, count($siblingDuplicateKeys), 'exactement 283 doublons soeurs attendus (D-045)');
    Assert::same(count($duplicateParentKeys), count(array_unique($duplicateParentKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(count($siblingDuplicateKeys), count(array_unique($siblingDuplicateKeys)), 'aucun doublon dans la liste figee elle-meme');
    Assert::same(0, count(array_intersect($duplicateParentKeys, $siblingDuplicateKeys)), 'les deux listes doivent rester disjointes');

    // --- Cas connu, verifie manuellement (rapport de tache D-045) : B:A:Q est un doublon PARENT
    // --- exact contre 'B:Q' (tous les mots de B+Q contiennent deja A) -- ne doit jamais
    // --- apparaitre depuis terminant/b/avec/q, la lettre A y serait manquante.
    Assert::true(in_array('B:A:Q', $duplicateParentKeys, true), 'sanity check : B:A:Q fait bien partie de la liste figee');
    $linksBQ = $builder->build('B', 'Q');
    $foundA = array_values(array_filter($linksBQ->links, static fn (array $l): bool => $l['letter'] === 'A'));
    Assert::true($foundA === [], 'B:A:Q est un doublon parent exact (D-045) -- ne doit jamais etre produit depuis terminant/b/avec/q');
    $rawCountBAQ = (int) $pdo->query("SELECT count FROM list_counts WHERE list_type = 'end_with_pair' AND list_key = 'B:A:Q'")->fetch()['count'];
    Assert::true($rawCountBAQ > 0, 'sanity check : B:A:Q existe bien dans list_counts (precalcul brut inchange, seule la sortie du builder est filtree)');

    // --- Toutes les cles des deux listes figees respectent le format et l'ordre attendus. ---
    foreach (array_merge($duplicateParentKeys, $siblingDuplicateKeys) as $key) {
        $parts = explode(':', $key);
        Assert::same(3, count($parts), "format de cle invalide : {$key}");
        [$suffix, $l1, $l2] = $parts;
        Assert::true($l1 < $l2, "lettres non ordonnees dans la cle figee : {$key}");
        Assert::true($suffix !== $l1 && $suffix !== $l2, "cle degeneree (D-032) trouvee dans une liste figee : {$key}");
    }
};
