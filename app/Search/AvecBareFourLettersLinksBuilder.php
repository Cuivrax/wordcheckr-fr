<?php

declare(strict_types=1);

namespace App\Search;

use App\Database\Connection;

/**
 * Construit App\Search\AvecBareFourLettersLinks depuis list_counts (list_type 'avec_bare_quad'),
 * meme principe que App\Search\AvecBareThreeLettersLinksBuilder (palier 2 -> 3) -- une seule
 * requete triviale, aucun calcul sur `terms` au runtime (voir scripts/build_explore_hub_counts.php
 * pour le precalcul). Dernier palier borne de la famille "avec" bare, D-049.
 *
 * list_key est toujours "{lettre1}:{lettre2}:{lettre3}:{lettre4}" avec
 * lettre1 < lettre2 < lettre3 < lettre4 ALPHABETIQUEMENT (une seule ligne par quadruplet non
 * ordonne). Depuis une page "avec {X} {Y} {Z}" bare (palier 3, deja triee $x < $y < $z par
 * WordListFilters::fromPath()), le triplet source peut occuper QUATRE positions differentes dans
 * le quadruplet trie stocke, selon ou tombe le quatrieme partenaire dans l'ordre alphabetique :
 *
 *   partenaire < X < Y < Z   -> quadruplet stocke "{partenaire}:{X}:{Y}:{Z}"
 *   X < partenaire < Y < Z   -> quadruplet stocke "{X}:{partenaire}:{Y}:{Z}"
 *   X < Y < partenaire < Z   -> quadruplet stocke "{X}:{Y}:{partenaire}:{Z}"
 *   X < Y < Z < partenaire   -> quadruplet stocke "{X}:{Y}:{Z}:{partenaire}"
 *
 * Quatre motifs LIKE distincts, combines par un seul OR dans une seule requete -- generalisation
 * directe du meme principe a trois motifs deja utilise par App\Search\AvecThreeLettersLinksBuilder/
 * App\Search\AvecBareThreeLettersLinksBuilder (voir leurs docblocks respectifs).
 *
 * L'URL cible est TOUJOURS construite via WordListFilters::fromPath()->canonicalUrl(), jamais
 * assemblee a la main.
 */
final class AvecBareFourLettersLinksBuilder
{
    // CORRECTIF PERF PROACTIF (2026-09-01) : meme classe de bug trouvee et corrigee dans
    // App\Search\AvecFourLettersLinksBuilder (in_array() lineaire sur des constantes de
    // plusieurs milliers d'elements, jusqu'a >30 min sur la verification exhaustive du test
    // associe) -- appliquee ici par avance : ces 4 constantes sont petites pour l'instant mais
    // vont grossir au fil du balayage de nettoyage post-publication (D-049), meme risque a terme.
    private static ?array $duplicateParentKeySet = null;
    private static ?array $siblingDuplicateKeySet = null;
    private static ?array $externalDuplicateKeySet = null;
    private static ?array $overBudgetKeySet = null;
    private static ?array $pendingVerificationKeySet = null;

    /**
     * Doublons de contenu avec une page PARENTE palier 1/2/3 (D-049, transitif). CORRECTIF
     * trouve en revisant ce builder AVANT toute application (jamais un lien vivant vers une
     * page devenue noindex, meme defaut deja trouve et corrige apres coup pour D-045/D-047 --
     * voir docs/DECISIONS.md D-049).
     * SOURCE DE VERITE = scripts/seo-batches/avec-bare-four-letters-2026-09-01.php.
     *
     * @var list<string>
     */
    private const DUPLICATE_PARENT_KEYS = [
        'A:B:J:Q', 'A:B:J:W', 'A:B:J:Y', 'A:D:J:W', 'A:D:K:X', 'A:E:J:W', 'A:F:J:Q', 'A:F:J:X',
        'A:F:P:W', 'A:F:Q:W', 'A:F:V:W', 'A:G:H:J', 'A:H:K:X', 'A:I:J:W', 'A:J:K:Q', 'A:J:K:V',
        'A:J:L:W', 'A:J:L:X', 'A:J:M:W', 'A:J:N:W', 'A:J:O:W', 'A:J:P:Q', 'A:J:P:X', 'A:J:P:Y',
        'A:J:Q:X', 'A:J:R:W', 'A:J:S:W', 'A:J:T:W', 'A:J:U:W', 'A:J:V:X', 'A:J:W:Y', 'A:J:W:Z',
        'A:K:M:X', 'A:K:P:X', 'A:K:Q:U', 'A:K:V:W', 'A:K:V:X', 'A:K:X:Z', 'A:M:Q:W', 'A:M:V:W',
        'A:N:W:X', 'A:Q:U:V', 'A:Q:U:X', 'A:T:W:X', 'A:W:X:Y', 'B:D:Q:U', 'B:E:F:W', 'B:E:P:V',
        'B:E:P:W', 'B:E:Q:X', 'B:E:W:X', 'B:E:X:Z', 'B:F:Q:U', 'B:G:Q:U', 'B:I:J:V', 'B:J:K:X',
        'B:J:L:W', 'B:J:O:W', 'B:J:Q:U', 'B:J:S:W', 'B:J:U:W', 'B:J:U:X', 'B:J:W:Y', 'B:K:Q:U',
        'B:P:R:W', 'B:Q:R:U', 'B:Q:U:V', 'B:Q:U:X', 'B:Q:U:Y', 'B:Q:U:Z', 'B:R:W:X', 'B:S:W:X',
        'B:U:W:X', 'C:E:V:W', 'C:F:V:W', 'C:G:Q:U', 'C:I:V:W', 'C:J:K:Q', 'C:J:Q:U', 'C:K:Q:U',
        'C:O:V:W', 'C:P:W:X', 'C:Q:U:W', 'C:Q:U:X', 'C:Q:U:Y', 'C:Q:U:Z', 'C:Q:V:W', 'D:E:J:X',
        'D:E:K:Q', 'D:E:K:X', 'D:E:Q:W', 'D:E:V:W', 'D:E:W:Z', 'D:G:Q:U', 'D:G:V:W', 'D:H:J:Y',
        'D:H:V:W', 'D:I:J:W', 'D:I:J:X', 'D:I:V:W', 'D:J:N:W', 'D:J:R:Y', 'D:J:U:X', 'D:K:Q:U',
        'D:K:Q:W', 'D:L:Q:U', 'D:L:V:W', 'D:M:Q:U', 'D:N:V:W', 'D:O:Q:W', 'D:O:V:W', 'D:P:Q:U',
        'D:P:Q:W', 'D:Q:T:U', 'D:Q:U:W', 'D:Q:U:X', 'D:Q:U:Y', 'D:R:V:W', 'D:S:V:W', 'D:U:V:W',
        'E:F:G:Q', 'E:F:G:Z', 'E:F:J:M', 'E:F:J:P', 'E:F:J:Q', 'E:F:K:Q', 'E:F:K:V', 'E:F:K:Z',
        'E:F:P:W', 'E:F:P:Z', 'E:F:Q:U', 'E:F:Q:V', 'E:F:Q:Y', 'E:F:V:W', 'E:F:V:Z', 'E:F:W:Z',
        'E:F:X:Z', 'E:F:Y:Z', 'E:G:P:W', 'E:G:Q:U', 'E:G:Q:V', 'E:G:Q:W', 'E:G:Q:X', 'E:G:Q:Z',
        'E:G:V:W', 'E:G:V:Z', 'E:G:W:X', 'E:G:X:Z', 'E:H:J:Q', 'E:H:J:V', 'E:H:J:Y', 'E:H:J:Z',
        'E:H:K:Q', 'E:H:M:Q', 'E:H:Q:V', 'E:H:Q:W', 'E:H:Q:X', 'E:H:Q:Y', 'E:H:Q:Z', 'E:H:V:W',
        'E:H:V:X', 'E:H:W:X', 'E:J:K:Q', 'E:J:K:V', 'E:J:K:X', 'E:J:K:Z', 'E:J:L:W', 'E:J:O:W',
        'E:J:P:Q', 'E:J:P:Z', 'E:J:Q:U', 'E:J:Q:V', 'E:J:Q:Z', 'E:J:R:W', 'E:J:T:W', 'E:J:U:W',
        'E:J:V:Z', 'E:J:X:Z', 'E:K:L:Q', 'E:K:P:Q', 'E:K:Q:U', 'E:K:Q:V', 'E:K:Q:W', 'E:K:Q:Y',
        'E:K:Q:Z', 'E:K:V:W', 'E:K:V:X', 'E:K:V:Y', 'E:K:X:Z', 'E:L:W:X', 'E:L:W:Z', 'E:L:X:Z',
        'E:M:Q:W', 'E:M:Q:Z', 'E:M:V:W', 'E:P:Q:W', 'E:P:W:Y', 'E:P:W:Z', 'E:P:X:Z', 'E:Q:T:W',
        'E:Q:U:X', 'E:Q:V:W', 'E:Q:V:X', 'E:Q:V:Y', 'E:Q:V:Z', 'E:Q:W:Y', 'E:Q:W:Z', 'E:Q:X:Y',
        'E:Q:X:Z', 'E:R:W:X', 'E:S:X:Z', 'E:T:V:W', 'E:U:V:W', 'E:U:W:X', 'E:V:W:Z', 'F:G:I:V',
        'F:G:J:U', 'F:G:Q:U', 'F:I:J:P', 'F:I:J:Q', 'F:I:K:Q', 'F:I:K:V', 'F:I:Q:V', 'F:I:Q:X',
        'F:I:V:W', 'F:I:W:Y', 'F:J:N:Q', 'F:J:N:X', 'F:J:O:P', 'F:J:O:X', 'F:J:Q:U', 'F:J:T:X',
        'F:K:N:V', 'F:K:O:V', 'F:K:Q:U', 'F:K:U:X', 'F:L:Q:U', 'F:M:Q:U', 'F:N:Q:U', 'F:N:V:W',
        'F:O:P:W', 'F:O:V:W', 'F:P:R:W', 'F:Q:R:U', 'F:Q:T:U', 'F:Q:U:V', 'F:Q:U:X', 'F:Q:U:Y',
        'F:T:V:W', 'F:U:V:W', 'G:H:Q:U', 'G:I:J:K', 'G:I:J:Y', 'G:I:K:Q', 'G:I:P:W', 'G:I:Q:W',
        'G:I:V:W', 'G:I:W:X', 'G:J:K:O', 'G:J:O:Y', 'G:J:Q:U', 'G:J:U:Y', 'G:K:Q:U', 'G:L:Q:U',
        'G:L:W:X', 'G:N:V:W', 'G:O:W:X', 'G:P:Q:U', 'G:Q:R:U', 'G:Q:T:U', 'G:Q:U:V', 'G:Q:U:W',
        'G:Q:U:X', 'G:Q:U:Y', 'G:Q:U:Z', 'G:R:W:X', 'G:S:V:W', 'G:S:W:X', 'H:I:K:Q', 'H:I:V:W',
        'H:I:W:X', 'H:J:O:Q', 'H:J:O:V', 'H:J:Q:U', 'H:J:R:Y', 'H:J:T:V', 'H:K:Q:U', 'H:K:Q:W',
        'H:K:U:X', 'H:L:Q:U', 'H:N:V:W', 'H:O:V:W', 'H:P:Q:W', 'H:Q:R:U', 'H:Q:U:V', 'H:Q:U:W',
        'H:Q:U:X', 'H:Q:U:Y', 'H:Q:U:Z', 'H:R:V:W', 'H:S:V:W', 'I:J:K:V', 'I:J:P:Q', 'I:J:Q:U',
        'I:J:Q:V', 'I:J:Q:X', 'I:J:S:W', 'I:J:T:W', 'I:J:W:Y', 'I:K:Q:U', 'I:K:Q:V', 'I:K:Q:W',
        'I:K:V:X', 'I:K:V:Y', 'I:K:W:Y', 'I:M:Q:W', 'I:M:V:W', 'I:P:Q:U', 'I:P:Q:W', 'I:Q:U:X',
        'I:Q:V:W', 'I:T:V:W', 'I:U:V:W', 'I:U:W:X', 'I:V:W:Z', 'I:W:X:Y', 'I:W:Y:Z', 'J:K:N:Q',
        'J:K:N:V', 'J:K:O:Q', 'J:K:O:X', 'J:K:P:V', 'J:K:Q:S', 'J:K:Q:U', 'J:K:U:X', 'J:L:N:W',
        'J:L:O:W', 'J:L:Q:U', 'J:L:R:W', 'J:L:U:W', 'J:L:U:X', 'J:M:N:W', 'J:M:O:W', 'J:M:Q:U',
        'J:M:W:Z', 'J:N:Q:U', 'J:N:U:W', 'J:N:W:Z', 'J:O:Q:U', 'J:O:Q:V', 'J:O:R:W', 'J:O:S:W',
        'J:O:T:W', 'J:O:U:W', 'J:O:W:Y', 'J:O:W:Z', 'J:O:X:Y', 'J:P:Q:U', 'J:P:U:X', 'J:Q:S:U',
        'J:Q:T:U', 'J:Q:U:V', 'J:Q:U:X', 'J:Q:U:Z', 'J:R:T:W', 'J:R:U:W', 'J:U:V:X', 'J:U:X:Y',
        'K:L:Q:U', 'K:L:Q:W', 'K:L:V:Y', 'K:L:X:Z', 'K:M:Q:U', 'K:N:Q:U', 'K:N:X:Z', 'K:O:Q:U',
        'K:O:Q:V', 'K:O:Q:W', 'K:O:Q:Z', 'K:O:X:Z', 'K:P:Q:U', 'K:P:Q:W', 'K:P:T:X', 'K:P:U:X',
        'K:Q:R:U', 'K:Q:R:Z', 'K:Q:S:U', 'K:Q:T:U', 'K:Q:U:V', 'K:Q:U:W', 'K:Q:U:Y', 'K:Q:U:Z',
        'K:R:V:W', 'L:M:Q:U', 'L:O:V:W', 'L:P:Q:U', 'L:P:Q:W', 'L:Q:R:U', 'L:Q:T:U', 'L:Q:U:V',
        'L:Q:U:X', 'L:Q:U:Z', 'L:U:W:X', 'M:N:Q:W', 'M:Q:U:V', 'M:Q:U:W', 'M:Q:U:X', 'M:Q:U:Y',
        'M:Q:U:Z', 'N:O:W:X', 'N:Q:U:V', 'N:Q:U:W', 'N:Q:U:X', 'N:Q:U:Y', 'N:T:W:X', 'N:V:W:Z',
        'O:P:Q:W', 'O:P:W:X', 'O:Q:U:W', 'O:Q:U:X', 'O:Q:U:Y', 'O:Q:U:Z', 'O:Q:V:W', 'O:U:W:X',
        'P:Q:R:U', 'P:Q:T:U', 'P:Q:U:V', 'P:Q:U:W', 'P:Q:U:X', 'P:Q:U:Y', 'P:Q:U:Z', 'Q:R:U:V',
        'Q:R:U:X', 'Q:R:V:W', 'Q:R:W:Z', 'Q:S:U:V', 'Q:S:U:X', 'Q:S:V:W', 'Q:T:U:V', 'Q:T:U:X',
        'Q:T:W:Z', 'Q:U:V:W', 'Q:U:V:X', 'Q:U:V:Y', 'Q:U:V:Z', 'Q:U:X:Y', 'Q:U:X:Z', 'Q:U:Y:Z',
        'R:U:W:X', 'S:U:W:X', 'T:V:W:Z', 'T:W:X:Y',
    ];

    /**
     * Doublons de contenu entre pages SOEURS du meme palier (D-049, empreinte SQL reelle).
     *
     * @var list<string>
     */
    private const SIBLING_DUPLICATE_KEYS = [
        'A:H:W:X', 'B:F:I:W', 'B:F:N:W', 'B:J:N:W', 'B:J:R:W', 'C:E:W:X', 'C:F:G:W', 'C:H:W:X',
        'C:I:W:X', 'C:R:W:X', 'D:F:G:W', 'D:P:U:W', 'E:J:W:Z', 'F:G:T:W', 'F:G:U:W', 'F:H:K:Y',
        'F:H:V:Y', 'F:I:K:X', 'F:J:M:X', 'F:J:U:X', 'F:K:M:V', 'F:K:T:V', 'F:K:T:Y', 'F:P:V:Y',
        'G:J:K:R', 'G:K:P:Q', 'G:K:Q:Y', 'G:Q:R:W', 'H:K:L:Q', 'H:K:L:X', 'H:K:N:X', 'H:K:O:X',
        'H:K:T:X', 'H:P:U:W', 'H:R:W:X', 'H:U:W:Z', 'I:J:R:W', 'I:K:P:X', 'J:L:U:Y', 'J:N:Q:V',
        'J:N:Q:X', 'J:N:R:W', 'J:O:Q:X', 'J:Q:R:V', 'J:Q:T:V', 'J:T:X:Y', 'K:L:S:X', 'K:M:P:W',
        'K:N:V:X', 'K:O:P:X', 'K:O:X:Y', 'K:P:U:W', 'K:S:V:X', 'L:M:Q:W', 'M:N:W:X', 'M:O:Q:W',
        'M:Q:R:W', 'M:Q:S:W', 'M:Q:T:W', 'M:T:W:X',
    ];

    /**
     * Doublons de contenu CROISES avec une famille EXTERIEURE deja indexee (D-049, meme
     * discipline D-041).
     *
     * @var list<string>
     */
    private const EXTERNAL_DUPLICATE_KEYS = [
        'A:F:Q:X', 'A:F:X:Z', 'A:J:X:Y', 'A:J:X:Z', 'B:C:F:X', 'B:C:J:Q', 'B:C:J:Y', 'B:D:G:J',
        'B:D:J:Q', 'B:D:K:Z', 'B:D:V:Z', 'B:F:M:W', 'B:F:R:W', 'B:F:V:Z', 'B:G:H:W', 'B:G:K:W',
        'B:G:P:W', 'B:H:J:Y', 'B:H:K:V', 'B:H:P:W', 'B:H:V:Z', 'B:I:K:X', 'B:J:K:L', 'B:J:S:X',
        'B:J:T:V', 'B:J:T:Y', 'B:J:T:Z', 'B:K:N:X', 'B:K:P:Z', 'B:K:U:W', 'B:L:W:X', 'B:M:W:Y',
        'B:O:W:X', 'B:P:V:Y', 'B:Q:Y:Z', 'B:U:W:Y', 'C:D:J:Y', 'C:D:Q:W', 'C:F:J:X', 'C:G:J:Y',
        'C:G:K:X', 'C:H:J:Y', 'C:H:Q:W', 'C:H:V:W', 'C:J:S:X', 'C:K:P:Z', 'C:K:S:X', 'C:L:X:Z',
        'C:N:V:W', 'C:S:V:W', 'D:F:L:W', 'D:F:U:Z', 'D:F:V:Z', 'D:H:V:X', 'D:H:V:Z', 'D:J:M:X',
        'D:J:Q:X', 'D:J:S:X', 'D:J:U:Y', 'D:K:L:X', 'D:K:O:X', 'D:K:P:Q', 'D:K:Q:Y', 'D:K:S:X',
        'D:K:T:X', 'D:K:U:V', 'D:K:V:X', 'D:K:X:Y', 'D:L:P:W', 'D:L:Q:W', 'D:O:P:W', 'D:P:R:W',
        'D:P:W:Y', 'D:Q:R:W', 'D:U:W:Y', 'E:J:Q:X', 'E:J:X:Y', 'E:N:W:X', 'F:G:K:O', 'F:G:O:W',
        'F:G:P:Q', 'F:G:P:V', 'F:G:P:X', 'F:G:W:Z', 'F:H:Q:U', 'F:H:T:W', 'F:I:J:X', 'F:I:X:Z',
        'F:J:L:P', 'F:J:P:T', 'F:J:P:U', 'F:J:S:X', 'F:J:U:Z', 'F:K:L:Y', 'F:K:M:X', 'F:K:O:X',
        'F:K:P:U', 'F:K:Q:R', 'F:K:R:V', 'F:K:T:X', 'F:K:W:Y', 'F:L:M:Z', 'F:L:P:W', 'F:L:Q:X',
        'F:L:Q:Z', 'F:L:W:Y', 'F:N:W:Y', 'F:N:W:Z', 'F:O:Q:X', 'F:O:W:Y', 'F:P:Q:U', 'F:P:U:Z',
        'F:P:W:Y', 'F:P:W:Z', 'F:Q:R:X', 'F:Q:S:X', 'F:R:W:Y', 'F:R:X:Z', 'F:S:X:Z', 'F:U:Y:Z',
        'F:W:Y:Z', 'G:H:J:T', 'G:H:P:W', 'G:I:W:Y', 'G:J:K:Y', 'G:J:M:Y', 'G:J:N:V', 'G:J:N:Y',
        'G:J:Q:S', 'G:K:O:Q', 'G:K:O:X', 'G:K:O:Z', 'G:K:Q:R', 'G:K:T:V', 'G:K:U:W', 'G:K:U:X',
        'G:L:V:W', 'G:N:Q:W', 'G:O:Q:W', 'G:O:V:W', 'G:O:W:Y', 'G:P:R:W', 'G:P:V:X', 'G:Q:S:W',
        'G:R:V:W', 'G:R:W:Y', 'G:U:V:W', 'G:U:W:Y', 'H:I:J:Q', 'H:I:J:Y', 'H:J:K:P', 'H:J:K:U',
        'H:J:K:Z', 'H:J:M:V', 'H:J:M:Z', 'H:J:N:Q', 'H:J:P:V', 'H:J:S:Y', 'H:K:Q:V', 'H:K:V:X',
        'H:K:W:Z', 'H:L:V:W', 'H:L:V:Z', 'H:L:W:Y', 'H:M:W:X', 'H:O:W:X', 'H:Q:W:Y', 'H:S:W:X',
        'H:T:W:Y', 'H:U:V:W', 'H:U:W:Y', 'I:J:X:Z', 'I:K:V:W', 'I:L:X:Z', 'J:K:L:Y', 'J:K:M:Y',
        'J:K:M:Z', 'J:L:R:Y', 'J:L:X:Y', 'J:M:Q:X', 'J:N:P:Y', 'J:N:V:Y', 'J:N:X:Y', 'J:N:Y:Z',
        'J:O:V:Y', 'J:O:Y:Z', 'J:P:Q:R', 'J:P:R:Y', 'J:P:T:X', 'J:Q:R:X', 'J:Q:S:V', 'J:R:V:Y',
        'J:R:X:Z', 'J:S:X:Z', 'J:T:X:Z', 'J:U:V:Y', 'J:U:X:Z', 'K:M:Q:Z', 'K:M:S:X', 'K:M:U:X',
        'K:N:R:X', 'K:O:V:W', 'K:O:V:Y', 'K:P:U:Z', 'K:Q:R:V', 'K:R:W:Y', 'K:T:V:W', 'K:T:V:Y',
        'K:U:V:X', 'L:M:W:X', 'L:O:W:X', 'L:P:W:Y', 'L:P:W:Z', 'L:Q:U:W', 'L:Q:W:Y', 'L:R:X:Z',
        'L:U:W:Y', 'L:U:X:Z', 'L:W:Y:Z', 'M:O:V:W', 'M:P:V:Z', 'M:P:X:Z', 'M:U:W:Y', 'N:P:W:Y',
        'N:P:W:Z', 'N:Q:T:W', 'N:S:W:X', 'N:V:X:Z', 'O:Q:W:Y', 'O:R:W:X', 'O:S:W:X', 'O:T:W:X',
        'O:V:X:Z', 'P:Q:X:Z', 'P:S:X:Z', 'P:W:Y:Z', 'Q:R:U:W', 'Q:T:U:W', 'Q:T:W:Y', 'Q:U:W:Y',
        'R:U:W:Y', 'R:W:Y:Z', 'S:W:X:Y', 'T:V:X:Y',
    ];

    /**
     * Quadruplets HORS BUDGET TTFB (D-049, meme mecanisme que les trois constantes ci-dessus,
     * PAS un doublon de contenu) -- source de verite = le lot versionne lui-meme
     * (scripts/seo-batches/avec-bare-four-letters-*.php). Voir docs/DECISIONS.md D-049 pour la
     * methodologie de mesure complete.
     *
     * @var list<string>
     */
    private const OVER_BUDGET_KEYS = [];

    /**
     * Pages en quarantaine (D-049bis, C-3) : jamais comparees a aucune autre famille,
     * mises en noindex,follow avec canonical sur elles-memes en attendant le balayage de
     * nettoyage -- PAS des doublons confirmes (contrairement aux trois constantes ci-dessus),
     * distinction volontaire (I-7, audit code-reviewer). A vider au fur et a mesure que le
     * balayage confirme chaque page (propre ou doublon reel).
     *
     * @var list<string>
     */
    private const PENDING_VERIFICATION_KEYS = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * $letter1/$letter2/$letter3 : les trois lettres "avec" de la page palier 3 bare source,
     * dans n'importe quel ordre (triees ici par defense).
     */
    public function build(string $letter1, string $letter2, string $letter3): AvecBareFourLettersLinks
    {
        $triple = [$letter1, $letter2, $letter3];
        sort($triple, SORT_STRING);
        [$x, $y, $z] = $triple;

        $statement = $this->connection->pdo()->prepare(
            "SELECT list_key, count FROM list_counts WHERE list_type = 'avec_bare_quad'"
            . ' AND (list_key LIKE ? OR list_key LIKE ? OR list_key LIKE ? OR list_key LIKE ?)'
        );
        $statement->execute([
            '%:' . $x . ':' . $y . ':' . $z,
            $x . ':%:' . $y . ':' . $z,
            $x . ':' . $y . ':%:' . $z,
            $x . ':' . $y . ':' . $z . ':%',
        ]);

        self::$duplicateParentKeySet ??= array_flip(self::DUPLICATE_PARENT_KEYS);
        self::$siblingDuplicateKeySet ??= array_flip(self::SIBLING_DUPLICATE_KEYS);
        self::$externalDuplicateKeySet ??= array_flip(self::EXTERNAL_DUPLICATE_KEYS);
        self::$overBudgetKeySet ??= array_flip(self::OVER_BUDGET_KEYS);
        self::$pendingVerificationKeySet ??= array_flip(self::PENDING_VERIFICATION_KEYS);

        $links = [];

        foreach ($statement as $row) {
            $key = (string) $row['list_key'];

            if (
                isset(self::$duplicateParentKeySet[$key])
                || isset(self::$siblingDuplicateKeySet[$key])
                || isset(self::$externalDuplicateKeySet[$key])
                || isset(self::$overBudgetKeySet[$key])
                || isset(self::$pendingVerificationKeySet[$key])
            ) {
                continue;
            }

            $parts = explode(':', $key, 4);

            $partner = null;
            foreach ($parts as $candidate) {
                if ($candidate !== $x && $candidate !== $y && $candidate !== $z) {
                    $partner = $candidate;
                    break;
                }
            }

            if ($partner === null) {
                // Defensif, jamais attendu : $x, $y, $z sont toujours distincts (page palier 3
                // source), donc exactement une des quatre lettres du quadruplet stocke n'est
                // aucune des trois.
                continue;
            }

            $count = (int) $row['count'];
            $path = 'avec/' . strtolower($x) . '/' . strtolower($y) . '/' . strtolower($z) . '/' . strtolower($partner);
            $url = WordListFilters::fromPath($path)?->canonicalUrl();

            if ($url !== null) {
                $links[] = ['letter' => $partner, 'url' => $url, 'count' => $count];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a['letter'] <=> $b['letter']);

        return new AvecBareFourLettersLinks(links: $links, queryCount: 1);
    }
}
