<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Search\WordListSolver;
use Tests\Support\Assert;

/**
 * Exerce App\Search\WordListSolver sur la vraie base storage/dictionary_fr.sqlite (lecture
 * seule) : correction croisee par force brute pour chaque contrainte et plusieurs
 * combinaisons, comportement de pagination, et le plafond de securite
 * (WordListSolver::ROW_EXAMINATION_CEILING) -- meme methodologie que RackSolverTest.php.
 */
return function (): void {
    $dbPath = __DIR__ . '/../../storage/dictionary_fr.sqlite';
    Assert::true(is_file($dbPath), 'base manquante : ' . $dbPath);

    $connection = new Connection($dbPath);
    $solver = new WordListSolver($connection);
    $pdo = $connection->pdo();

    // --- Entree invalide ou hors perimetre : aucune liste, meme convention que
    // --- TermLookup::find() et RackSolver::solve(). ---
    Assert::null($solver->solve('inconnu/valeur'));
    Assert::null($solver->solve('position/3/r'), '"position" hors perimetre de cette phase');
    Assert::null($solver->solve(''), '/mots seul (aucune contrainte) refuse explicitement');

    // --- Longueur seule : EXACT, total = COUNT() direct sur idx_terms_length_normalized. ---
    $byLength = $solver->solve('7-lettres');
    Assert::notNull($byLength);
    Assert::true($byLength->exact);
    Assert::true(!$byLength->truncated);
    Assert::same(2, $byLength->queryCount);
    $expectedLengthCount = (int) $pdo->query('SELECT COUNT(*) c FROM terms WHERE length = 7')->fetch()['c'];
    Assert::same($expectedLengthCount, $byLength->total);
    Assert::same(WordListSolver::PAGE_SIZE, count($byLength->items));
    for ($i = 1; $i < count($byLength->items); $i++) {
        Assert::true($byLength->items[$i - 1]['normalized'] <= $byLength->items[$i]['normalized'], 'ordre alphabetique attendu');
    }
    foreach ($byLength->items as $item) {
        Assert::same(7, $item['length']);
        Assert::true(in_array($item['status'], ['admitted', 'french_not_admitted'], true), 'jamais STATUS_UNKNOWN sur une ligne de `terms` (D-013)');
    }

    // --- Prefixe seul : EXACT, verifie par force brute (pas un echantillon). ---
    $byPrefix = $solver->solve('commencant/qi');
    Assert::notNull($byPrefix);
    Assert::true($byPrefix->exact);
    $bruteForcePrefix = [];
    foreach ($pdo->query("SELECT normalized FROM terms WHERE normalized LIKE 'QI%'") as $row) {
        if (str_starts_with($row['normalized'], 'QI')) {
            $bruteForcePrefix[] = $row['normalized'];
        }
    }
    sort($bruteForcePrefix);
    Assert::same(count($bruteForcePrefix), $byPrefix->total);

    // --- Longueur + prefixe combines : intersection exacte. ---
    $comboPage = $solver->solve('7-lettres/commencant/ch');
    Assert::notNull($comboPage);
    Assert::true($comboPage->exact);
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM terms WHERE length = 7 AND normalized >= 'CH' AND normalized < 'CI'");
    $stmt->execute();
    $expectedCombo = (int) $stmt->fetch()['c'];
    Assert::same($expectedCombo, $comboPage->total);
    foreach ($comboPage->items as $item) {
        Assert::same(7, $item['length']);
        Assert::true(str_starts_with($item['normalized'], 'CH'));
    }

    // --- Terminant seul : verifie par force brute sur reversed. ---
    $bySuffix = $solver->solve('terminant/tion');
    Assert::notNull($bySuffix);
    foreach ($bySuffix->items as $item) {
        Assert::true(str_ends_with($item['normalized'], 'TION'));
    }
    $stmtSuffix = $pdo->prepare("SELECT COUNT(*) c FROM terms WHERE normalized LIKE '%TION'");
    $stmtSuffix->execute();
    $expectedSuffixTotal = (int) $stmtSuffix->fetch()['c'];
    // La liste bornee peut etre tronquee si le panier ancre depasse le plafond -- pas le cas
    // ici (2 907 correspondances mesurees, tres en-dessous de ROW_EXAMINATION_CEILING).
    Assert::true(!$bySuffix->truncated, 'le panier de longueur "TION" ne doit pas depasser le plafond');
    Assert::same($expectedSuffixTotal, $bySuffix->total);

    // --- Contenant : verifie par force brute (instr() cote SQL, str_contains() cote PHP). ---
    $contains = $solver->solve('contenant/che');
    Assert::notNull($contains);
    foreach ($contains->items as $item) {
        Assert::true(str_contains($item['normalized'], 'CHE'));
    }

    // --- Regression C1 (audit final, code-reviewer, bloquant) : "contenant" SEUL, sans aucun
    // --- ancrage (longueur/prefixe/suffixe), doit trouver TOUTES les correspondances de toute
    // --- la base, pas seulement celles situees dans les ROW_EXAMINATION_CEILING premiers mots
    // --- de l'ordre alphabetique. Avant la correction, "contenant/xyl" renvoyait total = 0
    // --- (aucune des 270 correspondances reelles ne figure parmi les 10 000 premiers mots
    // --- alphabetiques de la base) -- exactement le bug reproduit et corrige. XYL choisi pour
    // --- son total REEL mesure sous ROW_EXAMINATION_CEILING (270 < 10 000) : le total renvoye
    // --- doit donc etre EXACT, pas seulement "non tronque".
    $unanchoredContains = $solver->solve('contenant/xyl');
    Assert::notNull($unanchoredContains);
    $bruteForceXyl = (int) $pdo->query('SELECT COUNT(*) c FROM terms WHERE instr(normalized, \'XYL\') > 0')->fetch()['c'];
    Assert::true($bruteForceXyl > 0, 'sanity check : XYL doit avoir des correspondances reelles dans la base');
    Assert::true(!$unanchoredContains->truncated, 'XYL (' . $bruteForceXyl . ' correspondances) est sous le plafond, ne doit pas etre tronque');
    Assert::same($bruteForceXyl, $unanchoredContains->total, 'C1 : "contenant" sans ancrage doit trouver TOUTES les correspondances, pas seulement celles des 10 000 premiers mots alphabetiques');
    foreach ($unanchoredContains->items as $item) {
        Assert::true(str_contains($item['normalized'], 'XYL'));
    }

    // --- Regression C1, variante "avec" (minCount = 1, chemin optimise instr()) : meme
    // --- verification par force brute, plusieurs lettres combinees, sans aucun ancrage. ---
    $unanchoredWith = $solver->solve('avec/x/y/z');
    Assert::notNull($unanchoredWith);
    $bruteForceXyz = (int) $pdo->query('SELECT COUNT(*) c FROM terms WHERE instr(normalized, \'X\') > 0 AND instr(normalized, \'Y\') > 0 AND instr(normalized, \'Z\') > 0')->fetch()['c'];
    Assert::true(!$unanchoredWith->truncated, 'avec/x/y/z (' . $bruteForceXyz . ' correspondances) est sous le plafond, ne doit pas etre tronque');
    Assert::same($bruteForceXyz, $unanchoredWith->total, 'C1 : "avec" sans ancrage doit trouver TOUTES les correspondances');
    foreach ($unanchoredWith->items as $item) {
        Assert::true(str_contains($item['normalized'], 'X') && str_contains($item['normalized'], 'Y') && str_contains($item['normalized'], 'Z'));
    }

    // --- Avec, repetitions comptees : verifie par force brute (array_count_values). ---
    $withLetters = $solver->solve('avec/a/a/r');
    Assert::notNull($withLetters);
    foreach ($withLetters->items as $item) {
        $counts = array_count_values(str_split($item['normalized']));
        Assert::true(($counts['A'] ?? 0) >= 2, $item['normalized'] . ' doit contenir au moins 2 A');
        Assert::true(($counts['R'] ?? 0) >= 1, $item['normalized'] . ' doit contenir au moins 1 R');
    }
    Assert::true($withLetters->total > 0, 'sanity check : au moins un mot avec 2 A et 1 R doit exister');

    // --- Sans : aucune occurrence de la lettre exclue. ---
    $without = $solver->solve('sans/z');
    Assert::notNull($without);
    foreach ($without->items as $item) {
        Assert::true(!str_contains($item['normalized'], 'Z'));
    }

    // --- Motif : cases connues respectees position par position. ---
    $motif = $solver->solve('5-lettres/motif/c--e-');
    Assert::notNull($motif);
    Assert::true($motif->total > 0);
    foreach ($motif->items as $item) {
        Assert::same(5, strlen($item['normalized']));
        Assert::same('C', $item['normalized'][0]);
        Assert::same('E', $item['normalized'][3]);
    }

    // --- Combinaison prefixe + terminant : suffixe applique en predicat supplementaire.
    // --- Le panier ANCRE seul (prefixe "CH", 12 037 lignes) depasse ROW_EXAMINATION_CEILING,
    // --- mais depuis la correction C1 le plafond porte sur le panier ANCRE ET FILTRE combine
    // --- (9 correspondances reelles, verifie par force brute) : exact/non tronque desormais,
    // --- alors que l'ancien code (bloquant C1) l'aurait marque a tort "truncated" en ne
    // --- regardant que l'ancrage seul. ---
    $prefixSuffix = $solver->solve('commencant/ch/terminant/tion');
    Assert::notNull($prefixSuffix);
    foreach ($prefixSuffix->items as $item) {
        Assert::true(str_starts_with($item['normalized'], 'CH'));
        Assert::true(str_ends_with($item['normalized'], 'TION'));
    }
    Assert::true(in_array('CHELATION', array_column($prefixSuffix->items, 'normalized'), true), 'CHELATION doit apparaitre (commence par CH, termine par TION)');
    $bruteForceChTion = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE normalized >= 'CH' AND normalized < 'CI' AND normalized LIKE '%TION'")->fetch()['c'];
    Assert::true(!$prefixSuffix->truncated, 'C1 : le panier combine (CH + TION, ' . $bruteForceChTion . ' correspondances reelles) est sous le plafond, ne doit plus etre tronque a tort du seul fait que le panier ANCRE seul (CH, 12 037 lignes) le depasse');
    Assert::same($bruteForceChTion, $prefixSuffix->total, 'total exact attendu, panier combine sous le plafond');

    // --- Plafond de securite, toujours actif sur le panier COMBINE quand il depasse
    // --- reellement ROW_EXAMINATION_CEILING (pas seulement l'ancrage) : "CH" + "sans Z"
    // --- laisse 10 993 correspondances reelles (mesure), au-dessus du plafond. ---
    $anchoredTruncated = $solver->solve('commencant/ch/sans/z');
    Assert::notNull($anchoredTruncated);
    $bruteForceChSansZ = (int) $pdo->query("SELECT COUNT(*) c FROM terms WHERE normalized >= 'CH' AND normalized < 'CI' AND instr(normalized, 'Z') = 0")->fetch()['c'];
    Assert::true($bruteForceChSansZ > WordListSolver::ROW_EXAMINATION_CEILING, 'sanity check : le panier combine CH + sans Z doit reellement depasser le plafond pour que ce test ait un sens, obtenu ' . $bruteForceChSansZ);
    Assert::true($anchoredTruncated->truncated, 'panier combine reellement au-dessus du plafond -> truncated attendu');
    Assert::true(!$anchoredTruncated->exact, 'total non garanti exhaustif quand truncated = true');
    foreach ($anchoredTruncated->items as $item) {
        Assert::true(str_starts_with($item['normalized'], 'CH'));
        Assert::true(!str_contains($item['normalized'], 'Z'));
    }

    // --- Pagination : page 2 renvoie des elements differents, coherents avec page 1. ---
    $page1 = $solver->solve('7-lettres');
    $page2 = $solver->solve('7-lettres/page/2');
    Assert::notNull($page1);
    Assert::notNull($page2);
    Assert::same(1, $page1->page);
    Assert::same(2, $page2->page);
    Assert::true($page1->hasNextPage);
    Assert::true(!$page1->hasPreviousPage);
    Assert::true($page2->hasPreviousPage);
    Assert::same($page1->total, $page2->total, 'meme total sur les deux pages');
    $page1Words = array_column($page1->items, 'normalized');
    $page2Words = array_column($page2->items, 'normalized');
    Assert::same([], array_intersect($page1Words, $page2Words), 'aucun mot en commun entre page 1 et page 2');
    Assert::true(max($page1Words) < min($page2Words), 'page 2 suit alphabetiquement la page 1');

    // --- Budget de requetes : au plus 2, quelle que soit la combinaison de contraintes.
    // --- Regime EXACT (longueur/prefixe seuls ou combines) : toujours 2 (COUNT + LIMIT/OFFSET).
    // --- Regime BORNE : 1 requete quand l'ancrage est deja l'ordre d'affichage (normalized) --
    // --- fusion mesuree (audit final, code-optimizer, constat I-1), voir l'entete de
    // --- solveBounded() -- 2 requetes seulement pour l'ancrage sur suffixe (reversed), ou
    // --- l'ordre d'ancrage differe de l'ordre d'affichage. ---
    foreach ([$byLength, $byPrefix, $comboPage] as $result) {
        Assert::same(2, $result->queryCount, 'regime EXACT : toujours 2 requetes');
    }
    Assert::same(2, $bySuffix->queryCount, 'suffixe seul : ancrage reversed, 2 requetes (non fusionne)');
    foreach ([$contains, $unanchoredContains, $unanchoredWith, $withLetters, $without, $motif, $prefixSuffix, $anchoredTruncated] as $result) {
        Assert::same(1, $result->queryCount, 'regime BORNE, ancrage normalized (ou aucun ancrage) : fusionne a 1 requete');
    }
    foreach ([$byLength, $byPrefix, $comboPage, $bySuffix, $contains, $unanchoredContains, $unanchoredWith, $withLetters, $without, $motif, $prefixSuffix, $anchoredTruncated] as $result) {
        Assert::true($result->queryCount <= 10, 'budget de requetes indexees depasse');
    }

    // --- Redirection canonique geree par WordListFilters, deja testee par
    // --- WordListFiltersTest.php -- pas reteste ici pour eviter la duplication. ---
};
