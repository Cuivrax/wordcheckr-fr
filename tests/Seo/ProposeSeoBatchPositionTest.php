<?php

declare(strict_types=1);

use Tests\Support\Assert;

/**
 * scripts/propose_seo_batch.php, cas 'position' (regularisation du lot D-028, audit
 * seo-technical-auditor, constat C3) -- boite noire, un vrai sous-processus PHP, jamais contre
 * le vrai storage/seo_fr.sqlite (ce script ne l'ouvre de toute facon jamais, uniquement
 * storage/dictionary_fr.sqlite en lecture seule -- voir l'entete du script).
 *
 * Verifie que le lot regenere reste identique au lot deja applique le 2026-08-11 : ce n'est pas
 * une nouvelle proposition a chaque execution (contrairement aux autres cas de ce script,
 * batch_id/added_at dynamiques), c'est la reproduction exacte d'un lot existant.
 *
 * CORRECTIF (D-041, 2026-08-21, isD041Excluded()) : 2 329 -> 2 327 lignes (2 exclusions) --
 * detecteur generique scripts/check_combinatorial_duplicates.php, regle de priorite
 * resolveDuplicateWinner() (scripts/lib/seo_duplicate_priority.php). batch_id/added_at restent
 * FIXES (regularisation d'un lot deja applique, meme convention que D-040 pour les corrections
 * precedentes de cette serie -- "meme batch_id/added_at").
 *
 * CORRECTIF (D-047, 2026-08-31, isD041Excluded()) : 2 327 -> 2 305 lignes (22 exclusions
 * supplementaires) -- balayage generique complet post-D-045/D-046
 * (scripts/check_combinatorial_duplicates.php), doublons croises avec commencant/terminant.
 * D041_EXCLUDED_ROUTE_PATHS['word_list_position'] passe de 2 a 24 entrees.
 *
 * D-051 (2026-09-02) : le complement kaikki fait apparaitre 2 nouvelles positions a >= 1
 * resultat (non couvertes par les 24 exclusions D-047, qui restent inchangees) -- 2 305 ->
 * 2 307 lignes. batch_id/added_at restent FIXES (regularisation d'un lot deja applique, meme
 * convention que les correctifs precedents de cette serie).
 */
return function (): void {
    $root = __DIR__ . '/../..';
    $dictPath = $root . '/storage/dictionary_fr.sqlite';
    Assert::true(is_file($dictPath), 'base manquante : ' . $dictPath);

    $process = proc_open(
        [PHP_BINARY, $root . '/scripts/propose_seo_batch.php', 'position'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    Assert::true($process !== false, 'impossible de lancer propose_seo_batch.php');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    Assert::same(0, $exitCode, "propose_seo_batch.php position aurait du reussir : {$stderr}");
    Assert::true(str_contains($stderr, '2307 ligne(s) proposee(s)'), $stderr);

    $tmpFile = tempnam(sys_get_temp_dir(), 'position_batch_');
    file_put_contents($tmpFile, $stdout);

    try {
        $batch = require $tmpFile;

        Assert::same('position-full-2026-08-11', $batch['batch_id'], 'batch_id fixe attendu (regularisation, pas une nouvelle proposition)');
        Assert::same('2026-08-10', $batch['added_at']);
        // D-051 (2026-09-02) : remesure directe apres complement kaikki (2 positions liberees).
        Assert::same(2307, count($batch['rows']), '2 329 (D-028) moins 2 (D-041) moins 22 (D-047), plus 2 (D-051, complement kaikki, nouvelles positions a >= 1 resultat)');

        // --- Aucune position degeneree (1re ou derniere lettre, deja collapsee vers
        // --- commencant/terminant par WordListFilters::fromPath(), D-023). ---
        foreach ($batch['rows'] as $row) {
            Assert::true(preg_match('#^/mots/(\d+)-lettres/position/(\d+)/[a-z]$#', $row['route_path'], $m) === 1, $row['route_path']);
            $length = (int) $m[1];
            $position = (int) $m[2];
            Assert::true($position > 1 && $position < $length, "position degeneree inattendue : {$row['route_path']}");
        }

        // --- R1-R7 respectees par construction (sitemap_fragment unique, canonical = route,
        // --- notes non vide, result_count jamais 0 pour une ligne index,follow). ---
        $seen = [];
        foreach ($batch['rows'] as $row) {
            Assert::same('word_list_position', $row['family']);
            Assert::same('index,follow', $row['robots']);
            Assert::same($row['route_path'], $row['canonical_path'], 'R3 : jamais d\'alias indexable');
            Assert::same('position-0001', $row['sitemap_fragment']);
            Assert::true($row['result_count'] >= 1, 'R5 : jamais 0 resultat pour une ligne index,follow');
            Assert::true($row['result_count'] <= 10_000, 'plafond ROW_EXAMINATION_CEILING (D-019)');
            Assert::true(trim($row['notes']) !== '', 'R7 : note de maillage obligatoire');
            Assert::true(!isset($seen[$row['route_path']]), 'R2 : aucun doublon dans le lot');
            $seen[$row['route_path']] = true;
        }

        // --- Cas connu, verifie ligne par ligne (deja mesure dans D-028 et
        // --- reports/query-plans/position-family.md). ---
        $sample = array_values(array_filter($batch['rows'], static fn (array $r): bool => $r['route_path'] === '/mots/9-lettres/position/3/a'));
        Assert::true(count($sample) === 1);
        // D-051 (2026-09-02) : remesure directe, complement kaikki (etait 7992).
        Assert::same(8051, $sample[0]['result_count'], 'total remesure D-051, etait 7992 (reports/query-plans/position-family.md, base pre-kaikki)');
    } finally {
        unlink($tmpFile);
    }
};
