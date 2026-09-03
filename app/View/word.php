<?php

declare(strict_types=1);

/**
 * Vue fiche mot, appelee par public/index.php avec $page (App\Search\TermPage),
 * $relations (App\Search\TermRelations|null, Phase 4) et $conjugation
 * (App\Search\Conjugation, D-018). $relations est null uniquement pour un mot inconnu
 * (D-050, 2026-09-01 : calcule desormais pour tout mot TROUVE, admis ou francais non
 * admis, meme correctif que le depot espagnol cousin ES-047) -- aucune section relations
 * n'est alors rendue (aucune section vide, docs/01_MASTER_BRIEF.md).
 *
 * Le statut est ferme a trois valeurs (CLAUDE.md) : admitted / french_not_admitted /
 * unknown. Les deux premieres phrases de reponse directe reprennent le texte exact
 * de docs/01_MASTER_BRIEF.md (et docs/08_PROMPTS_PHASES.md pour le remplacement de
 * QUEULEULEU par GHOSTER) ; la phrase "unknown" est un texte fonctionnel minimal,
 * a confirmer par l'agent microcopy.
 *
 * french_not_admitted (D-054) : la phrase "Reponse Directe" varie selon
 * $page->nonAdmittedCategory (D-051, jeu ferme de 10 valeurs -- jamais affichee brute a
 * l'utilisateur, toujours une des 10 phrases dediees ou la phrase generique de repli).
 * NULL pour un mot admis et pour les 435 120 formes francaises non admises anterieures a
 * D-051 -- repli sur la phrase generique existante dans ce cas, aucune regression.
 *
 * Relations (Phase 4) : dix categories, seules les non vides sont rendues (voir
 * app/Search/TermRelations.php pour le contrat exact). Le surlignage <mark> de la partie
 * conservee/modifiee est calcule ici par simple comparaison de chaines entre le mot pivot
 * et chaque candidat -- sauf changeOneLetter, ou le backend fournit deja position/newLetter,
 * reutilises tels quels. Les liens "Voir les N mots ->" (rallonges, mot contenu) reutilisent
 * App\Search\WordListFilters::canonicalUrl(), jamais une URL construite a la main.
 *
 * Nature grammaticale / genre / conjugaison (D-018) : $page->pos, $page->posSecondary et
 * $page->gender sont nullables (absence de donnee Kartmaan pour ~12,3% des termes, pas une
 * erreur) -- aucune ligne rendue si $page->pos est null, meme convention "pas de section
 * vide" que le reste de la fiche. $conjugation n'est jamais null pour un mot TROUVE (admis
 * ou francais non admis), mais ses deux listes ($asLemma, $asForm) le sont le plus souvent
 * simultanement (mot ni verbe ni forme conjuguee) : la section conjugaison entiere est alors
 * absente.
 *
 * Definitions (D-0XX, pilote 100 mots -- revise D-004, voir reports/definitions-nature-
 * feasibility-audit.md) : $senses n'est jamais null pour un mot TROUVE, mais $senses->senses
 * est vide pour la tres grande majorite des termes tant que le lot reste un pilote partiel --
 * aucune section rendue dans ce cas, meme convention que le reste de la fiche. Des qu'au moins
 * un sens existe, $posLine (une phrase compacte de nature grammaticale) devient redondante avec
 * les cartes de sens (qui portent deja pos/genre chacune) -- $posLine n'est alors PAS rendue,
 * evite de dire deux fois la meme chose de deux facons differentes sur la meme page. Quand
 * $posLine EST rendue (aucun sens), elle reutilise le markup .sense-card/.sense-meta/.sense-
 * label/.sense-pos d'une vraie carte de sens plutot qu'un composant .pos-line distinct -- retour
 * utilisateur : la typo doit etre identique, pas seulement similaire, ce qui n'est garanti que
 * si c'est litteralement le meme CSS qui s'applique (voir bloc d'impression plus bas). Seule
 * difference : le libelle ("Nature Grammaticale" au lieu de "Définition") et l'absence de
 * .sense-text (il n'y a pas de definition a montrer dans ce cas).
 */

require __DIR__ . '/helpers.php';

use App\Search\Conjugation;
use App\Search\TermPage;
use App\Search\WordListFilters;
use App\Search\WordSenses;

/** @var TermPage $page */
/** @var \App\Seo\SeoMeta $seo */
/** @var \App\Search\TermRelations|null $relations */
/** @var Conjugation $conjugation */
/** @var WordSenses $senses */

// D-054 : phrase "Reponse Directe" specifique selon $page->nonAdmittedCategory (D-051,
// jeu ferme de 10 valeurs, jamais affiche brut a l'utilisateur -- toujours une des phrases
// ci-dessous). Repli sur la phrase generique quand nonAdmittedCategory est NULL (435 120
// formes anterieures a D-051 + tout mot dont la categorie n'a pas encore ete renseignee).
$notAdmittedGenericPhrase = '%s existe en français, mais ce mot n’est pas admis dans le dictionnaire officiel du Scrabble.';
$notAdmittedPhrasesByCategory = [
    'proper_noun' => '%s est un nom propre — les noms propres ne sont jamais admis au Scrabble, quelle que soit leur notoriété.',
    'acronym' => '%s est un sigle ou une abréviation, pas un mot du dictionnaire — les sigles ne sont pas admis au Scrabble.',
    'slang' => '%s est un mot d’argot, absent des dictionnaires officiels du Scrabble.',
    'colloquial' => '%s est un mot familier, non répertorié dans les dictionnaires officiels du Scrabble.',
    'regional' => '%s est un régionalisme, employé dans certaines régions mais absent des dictionnaires officiels du Scrabble.',
    'dialectal' => '%s est un mot dialectal, propre à un parler régional, non répertorié dans les dictionnaires officiels du Scrabble.',
    'archaic' => '%s est une graphie ancienne, tombée en désuétude — les dictionnaires actuels du Scrabble ne la reconnaissent pas.',
    'dated' => '%s est un mot vieilli, tombé en désuétude, absent des dictionnaires actuels du Scrabble.',
    'obsolete' => '%s est une ancienne orthographe, remplacée depuis par une autre graphie, non reconnue par les dictionnaires actuels du Scrabble.',
    'literary' => '%s est un mot littéraire rare, absent des dictionnaires officiels du Scrabble.',
];
$notAdmittedPhraseFormat = $page->nonAdmittedCategory !== null
    ? ($notAdmittedPhrasesByCategory[$page->nonAdmittedCategory] ?? $notAdmittedGenericPhrase)
    : $notAdmittedGenericPhrase;

$statusMeta = match ($page->status) {
    TermPage::STATUS_ADMITTED => [
        'modifier' => 'admitted',
        'badge' => 'Oui, Mot Admis',
        'subtitle' => 'Vous pouvez le jouer.',
        'direct' => sprintf(
            '%s est valide dans le dictionnaire officiel du Scrabble. Son score brut est de %d points, hors bonus de plateau.',
            $page->normalized,
            $page->score,
        ),
        'title' => sprintf('Oui, %s Est Admis Au Scrabble (%d Points)', $page->normalized, $page->score),
    ],
    TermPage::STATUS_FRENCH_NOT_ADMITTED => [
        'modifier' => 'not-admitted',
        'badge' => 'Non Admis',
        'subtitle' => 'Vous ne pouvez pas le jouer.',
        'direct' => sprintf($notAdmittedPhraseFormat, $page->normalized),
        'title' => sprintf('Non, %s N’est Pas Admis Au Scrabble', $page->normalized),
    ],
    default => [
        'modifier' => 'unknown',
        'badge' => 'Terme Inconnu',
        'subtitle' => 'Absent de la base.',
        'direct' => sprintf(
            '%s n’a pas été trouvé dans la base du site. Il ne peut pas être vérifié comme mot valide au Scrabble.',
            $page->normalized,
        ),
        'title' => sprintf('%s : Terme Inconnu', $page->normalized),
    ],
};

$letterList = implode(' + ', array_column($page->letters, 'letter'));
$tilesAriaLabel = sprintf('%s, total %d points', $letterList, $page->score);

// Relations (Phase 4) : construites uniquement si $relations !== null (mot TROUVE, voir doc
// de tete -- plus reserve aux seuls mots admis depuis D-050). Tout le calcul est de la simple
// comparaison de chaines et de la construction d'URL deja etablie ailleurs (WordListFilters)
// -- aucune requete, aucune logique metier nouvelle.
$relationCategories = [];
$relatedLabel = null;

if ($relations !== null) {
    $pivot = $page->normalized;

    $countLabel = static function (int $count, bool $truncated = false): string {
        if ($truncated) {
            return sprintf('Au moins %d mots', $count);
        }

        return $count > 1 ? sprintf('%d mots', $count) : sprintf('%d mot', $count);
    };

    $moreLinkLabel = static function (int $total, bool $truncated): string {
        return $truncated ? sprintf('Voir au moins %d mots →', $total) : sprintf('Voir les %d mots →', $total);
    };

    $extensionUrl = static function (string $keyword, string $word): ?string {
        return WordListFilters::fromPath($keyword . '/' . strtolower($word))?->canonicalUrl();
    };

    // Surlignage <mark> : position/newLetter deja fournis par le backend pour
    // changeOneLetter, reutilises tels quels. Pour les autres categories surlignees
    // (insertOneLetter, rightExtensions, leftExtensions, containingWords), simple
    // comparaison de chaines entre le mot pivot et le candidat -- pas de logique metier.
    // anagrams, removeOneLetter, substrings, anagramsPlusOne, anagramsMinusOne ne sont
    // jamais surlignes (meme convention que prototype/mot-poser.html).
    $highlighted = static function (array $item, string $key, string $pivot): string {
        $word = $item['normalized'];

        switch ($key) {
            case 'changeOneLetter':
                $pos = $item['position'] - 1;

                return e(substr($word, 0, $pos)) . '<mark>' . e($word[$pos]) . '</mark>' . e(substr($word, $pos + 1));

            case 'insertOneLetter':
                $pivotLength = strlen($pivot);
                $i = 0;

                while ($i < $pivotLength && $pivot[$i] === $word[$i]) {
                    $i++;
                }

                return e(substr($word, 0, $i)) . '<mark>' . e($word[$i]) . '</mark>' . e(substr($word, $i + 1));

            case 'rightExtensions':
                $pivotLength = strlen($pivot);

                return '<mark>' . e(substr($word, 0, $pivotLength)) . '</mark>' . e(substr($word, $pivotLength));

            case 'leftExtensions':
                $prefixLength = strlen($word) - strlen($pivot);

                return e(substr($word, 0, $prefixLength)) . '<mark>' . e(substr($word, $prefixLength)) . '</mark>';

            case 'containingWords':
                $at = strpos($word, $pivot);

                if ($at === false) {
                    return e($word);
                }

                return e(substr($word, 0, $at)) . '<mark>' . e($pivot) . '</mark>' . e(substr($word, $at + strlen($pivot)));

            default:
                return e($word);
        }
    };

    // Regroupement par lettre ajoutee ("+A", "+I", "+T"...), uniquement pour anagrammesPlusOne
    // (seule categorie ou le CSS du prototype prevoit .word-text/.plus) -- addedLetter est
    // deja fourni par TermRelations, aucune donnee inventee cote vue.
    $plusOneGroups = [];
    foreach ($relations->anagramsPlusOne as $item) {
        $plusOneGroups[$item['addedLetter']][] = $item;
    }
    ksort($plusOneGroups, SORT_STRING);

    $relationCategories = [
        [
            'key' => 'anagrams', 'title' => 'Anagrammes', 'items' => $relations->anagrams, 'full' => false,
            'count' => $countLabel(count($relations->anagrams)),
        ],
        [
            'key' => 'changeOneLetter', 'title' => 'Changer Une Lettre', 'items' => $relations->changeOneLetter, 'full' => false,
            'count' => $countLabel(count($relations->changeOneLetter)),
        ],
        [
            'key' => 'removeOneLetter', 'title' => 'Retirer Une Lettre', 'items' => $relations->removeOneLetter, 'full' => false,
            'count' => $countLabel(count($relations->removeOneLetter)),
        ],
        [
            'key' => 'insertOneLetter', 'title' => 'Insérer Une Lettre', 'items' => $relations->insertOneLetter, 'full' => false,
            'count' => $countLabel(count($relations->insertOneLetter)),
        ],
        [
            'key' => 'substrings', 'title' => 'Sous-Mots', 'items' => $relations->substrings, 'full' => false,
            'count' => $countLabel(count($relations->substrings)),
        ],
        [
            'key' => 'rightExtensions', 'title' => 'Rallonges À Droite', 'items' => $relations->rightExtensions, 'full' => true,
            'count' => $countLabel($relations->rightExtensionsTotal, $relations->rightExtensionsTruncated),
            'moreUrl' => count($relations->rightExtensions) < $relations->rightExtensionsTotal ? $extensionUrl('commencant', $pivot) : null,
            'moreLabel' => $moreLinkLabel($relations->rightExtensionsTotal, $relations->rightExtensionsTruncated),
        ],
        [
            'key' => 'leftExtensions', 'title' => 'Rallonges À Gauche', 'items' => $relations->leftExtensions, 'full' => true,
            'count' => $countLabel($relations->leftExtensionsTotal, $relations->leftExtensionsTruncated),
            'moreUrl' => count($relations->leftExtensions) < $relations->leftExtensionsTotal ? $extensionUrl('terminant', $pivot) : null,
            'moreLabel' => $moreLinkLabel($relations->leftExtensionsTotal, $relations->leftExtensionsTruncated),
        ],
        [
            'key' => 'containingWords', 'title' => $pivot . ' Dans Un Mot Plus Long', 'items' => $relations->containingWords, 'full' => true,
            'count' => $countLabel($relations->containingWordsTotal, $relations->containingWordsTruncated),
            // Pas de lien "Voir les N mots" ici (retire, audit final 3e passe, bloquant) :
            // pointerait vers /mots/contenant/{mot} SANS ancrage, exactement le parcours complet
            // de la table que la correction C1 rend correct mais couteux -- voir le commentaire
            // de RelationsFinder::relatedSearches() pour le detail complet. Le compte total
            // ($countLabel ci-dessus) reste affiche, seul le lien cliquable disparait.
            'moreUrl' => null,
            'moreLabel' => $moreLinkLabel($relations->containingWordsTotal, $relations->containingWordsTruncated),
        ],
        [
            'key' => 'anagramsPlusOne', 'title' => 'Anagrammes Avec Une Lettre En Plus', 'items' => $relations->anagramsPlusOne, 'full' => true,
            'count' => $countLabel(count($relations->anagramsPlusOne)),
            'groups' => $plusOneGroups,
        ],
        [
            'key' => 'anagramsMinusOne', 'title' => 'Anagrammes Avec Une Lettre En Moins', 'items' => $relations->anagramsMinusOne, 'full' => true,
            'count' => $countLabel(count($relations->anagramsMinusOne)),
        ],
    ];

    // Libelle lisible d'une recherche liee, reconstruit par reparse de l'URL deja fournie par
    // le backend (App\Search\WordListFilters::fromPath(), meme technique que app/View/word-list.php
    // pour son propre titre) -- jamais de concatenation manuelle de chaine metier.
    $relatedLabel = static function (array $link, string $pivot): string {
        if ($link['type'] === 'play') {
            return 'Jouer Avec ' . $pivot;
        }

        if ($link['type'] === 'exploreAll') {
            return 'Explorer Tous Les Mots';
        }

        $rawPath = preg_replace('#^/mots/#', '', $link['url']) ?? $link['url'];
        $filters = WordListFilters::fromPath($rawPath);

        if ($filters === null) {
            return $pivot;
        }

        if ($filters->withLetters !== []) {
            $letters = array_keys($filters->withLetters);
            $count = count($letters);
            $joined = $count > 1
                ? implode(', ', array_slice($letters, 0, -1)) . ' et ' . $letters[$count - 1]
                : $letters[0];

            return sprintf('%d Lettre%s Avec %s', $filters->length, $filters->length > 1 ? 's' : '', $joined);
        }

        if ($filters->prefix !== null) {
            return 'Commençant Par ' . $filters->prefix;
        }

        if ($filters->suffix !== null) {
            return 'Terminant Par ' . $filters->suffix;
        }

        if ($filters->contains !== null) {
            return 'Contenant ' . $filters->contains;
        }

        if ($filters->length !== null) {
            return sprintf('Mots De %d Lettre%s', $filters->length, $filters->length > 1 ? 's' : '');
        }

        return $pivot;
    };
}

// Nature grammaticale + genre (D-018) : une ligne discrete, absente si $page->pos est null
// (terme non couvert par Kartmaan -- absence de donnee, pas une erreur). Jeu ferme de 9
// codes, gender jamais associe a un pos autre que N (voir docs/DECISIONS.md D-018).
$posLabels = [
    'N' => 'Nom',
    'V' => 'Verbe',
    'Adj' => 'Adjectif',
    'Adv' => 'Adverbe',
    'Pronom' => 'Pronom',
    'Prep' => 'Préposition',
    'Conj' => 'Conjonction',
    'Interj' => 'Interjection',
    'Art' => 'Article',
];
$genderLabels = ['m' => 'masculin', 'f' => 'féminin', 'e' => 'épicène'];

// $posLine reste le repli quand aucune definition n'existe encore pour ce terme (lot
// partiel, D-0XX) -- des qu'au moins un sens existe, chaque carte de sens porte deja son
// propre pos/genre : $posLine deviendrait une redite, elle n'est alors pas construite. Le
// texte calcule ici est imprime plus bas dans le meme markup .sense-card qu'une vraie carte
// de sens (voir doc de tete) -- rien a changer ici, seule l'impression differe.
//
// D-0XX : meme raisonnement si le mot EST une forme conjuguee (asForm non vide) -- une carte
// "Definition" (reelle ou synthetisee depuis Conjugation, voir plus bas) va de toute facon
// etre affichee, $posLine y ferait alors doublon. Jamais observe sur les donnees reelles a ce
// jour (pos Kartmaan et word_senses/asForm ne se recoupent jamais vides simultanement en
// pratique), garde-fou par prudence plutot que suppose impossible.
$posLine = null;
if ($senses->senses === [] && $conjugation->asForm === [] && $page->pos !== null && isset($posLabels[$page->pos])) {
    $posLine = $posLabels[$page->pos];

    if ($page->pos === 'N' && $page->gender !== null && isset($genderLabels[$page->gender])) {
        $posLine .= ' ' . $genderLabels[$page->gender];
    }

    if ($page->posSecondary !== null && isset($posLabels[$page->posSecondary])) {
        $secondary = mb_strtolower($posLabels[$page->posSecondary]);

        if ($page->posSecondary === 'N' && $page->pos !== 'N' && $page->gender !== null && isset($genderLabels[$page->gender])) {
            $secondary .= ' ' . $genderLabels[$page->gender];
        }

        $posLine .= ', aussi ' . $secondary;
    }
}

// Conjugaison (D-018) : temps/personne traduits en francais lisible, jamais de tag anglais
// brut affiche. Selection representative fixe (docs/DECISIONS.md D-018) -- ordre canonique
// impose ici, pas l'ordre alphabetique renvoye par ConjugationLookup.
$tenseOrder = ['present', 'future', 'imperfect', 'participle_present', 'participle_past'];
$tenseLabels = [
    'present' => 'Présent',
    'future' => 'Futur',
    'imperfect' => 'Imparfait',
    'participle_present' => 'Participe présent',
    'participle_past' => 'Participe passé',
];
$personLabels = [
    '1s' => '1re pers. sing.',
    '2s' => '2e pers. sing.',
    '3s' => '3e pers. sing.',
    '1p' => '1re pers. plur.',
    '2p' => '2e pers. plur.',
    '3p' => '3e pers. plur.',
];
$personRank = array_flip(['1s', '2s', '3s', '1p', '2p', '3p']);
$tenseRank = array_flip($tenseOrder);

// D-0XX (rotation de gabarits, valide avec l'utilisateur apres correctif) : la phrase "cette
// page EST une forme conjuguee de LEMME" vit desormais UNIQUEMENT dans la carte "Definition"
// plus bas -- PAS une section "Conjugaison" separee (retour utilisateur explicite : la meme
// information a deux endroits, formulee differemment, lit comme une incoherence visuelle, pas
// une variation voulue -- captures d'ecran ABADONS/AMOCHE a l'appui). Un seul representant
// choisi par ordre canonique temps puis personne quand $conjugation->asForm porte plusieurs
// entrees pour le meme mot (rare, ex. TABLE -> TABLER present 1s/3s + participe passe) --
// jamais tous rendus separement, evite la repetition "4 fois la meme chose" signalee.
//
// Elision "du"/"au" vs "de l'"/"à l'" GENERIQUE (premiere lettre voyelle du temps) plutot
// qu'un dictionnaire fige par temps -- les noms de temps/mode grammaticaux sont TOUJOURS
// masculins en francais ("le present", "le passe simple", "l'imparfait"...), donc la regle
// d'elision ne depend que de la premiere lettre, jamais du temps precis. Permet de reutiliser
// EXACTEMENT la meme fonction pour les temps connus (verb_forms, ci-dessous) et pour les temps
// extraits en texte libre depuis une glose Kartmaan (repli plus bas, tous temps francais
// confondus -- passe simple, subjonctif, conditionnel... jamais enumeres a la main).
$renderVerbFormPhrase = static function (
    string $lemma,
    string $slug,
    ?string $tensePhrase,
    ?string $personLabel,
    int $variant,
): string {
    $link = '<a href="/mot/' . e($slug) . '">' . e($lemma) . '</a>';

    if ($tensePhrase === null) {
        // Aucune information de temps/personne du tout (glose source sans detail) -- lien
        // seul, aucun gabarit variable possible.
        return "Forme conjuguée de {$link}.";
    }

    if ($personLabel === null) {
        // Participe (le francais ne le conjugue pas par personne) ou temps sans personne
        // connue -- gabarit A original, seul gabarit valide sans personne.
        return "Forme conjuguée de {$link} ({$tensePhrase}).";
    }

    $firstChar = mb_strtolower(mb_substr($tensePhrase, 0, 1));
    $isVowel = in_array($firstChar, ['a', 'e', 'i', 'o', 'u', 'y'], true);
    $tensePrepositionDe = $isVowel ? ("de l'" . $tensePhrase) : ('du ' . $tensePhrase);
    $tensePrepositionA = $isVowel ? ("à l'" . $tensePhrase) : ('au ' . $tensePhrase);

    return match ($variant) {
        0 => "Forme conjuguée de {$link} ({$tensePhrase}, {$personLabel}).",
        1 => "{$personLabel} {$tensePrepositionDe} du verbe {$link}.",
        // $personLabel se termine deja par un point d'abreviation ("sing."/"plur.") -- AUCUN
        // point final ajoute ici, sinon double point ("sing..").
        2 => "Cette forme vient du verbe {$link}, conjugué {$tensePrepositionA} à la {$personLabel}",
        default => "Conjugaison à la {$personLabel} {$tensePrepositionDe} du verbe {$link}.",
    };
};

$conjugationVerbFormHtml = null;
if ($conjugation->asForm !== []) {
    $representative = $conjugation->asForm[0];
    $bestRank = null;
    foreach ($conjugation->asForm as $formEntry) {
        $rank = [$tenseRank[$formEntry['tense']] ?? 99, $personRank[$formEntry['person']] ?? 99];
        if ($bestRank === null || $rank < $bestRank) {
            $bestRank = $rank;
            $representative = $formEntry;
        }
    }

    $tenseKey = $representative['tense'];
    $tensePhrase = mb_strtolower($tenseLabels[$tenseKey] ?? $tenseKey);
    $personLabel = $representative['person'] !== null ? ($personLabels[$representative['person']] ?? null) : null;
    $variant = crc32($page->normalized) % 4;

    $conjugationVerbFormHtml = $renderVerbFormPhrase(
        $representative['lemma'],
        $representative['slug'],
        $tensePhrase,
        $personLabel,
        $variant,
    );
}

// D-0XX (repli) : ~65% des cartes "forme conjuguee" (pos=V, source=template) n'ont AUCUNE
// ligne verb_forms correspondante -- $conjugation->asForm reste alors vide et la logique
// ci-dessus ne produit rien, contrairement a ce qu'on avait suppose au premier correctif
// (retour utilisateur, ex. MANAGERA -> "manager" jamais un lien, jamais en majuscule). Racine
// du probleme : ces 172 579 cartes viennent d'un DEUXIEME mecanisme entierement independant
// (scripts/lib/reference_definitions.py::render_grammatical_template(), regex sur la glose
// Kartmaan BRUTE, jamais verb_forms) -- verifie exhaustivement sur les 172 579 lignes reelles,
// 3 formats fermes couvrent 172 503 (99,96%) :
//   "Forme conjuguée du verbe LEMME (TEMPS, ORDINAL personne du NOMBRE)." (140 724 lignes)
//   "Forme conjuguée du verbe LEMME." (20 628 lignes, aucun detail -- lien seul)
//   "Participe DETAIL du verbe LEMME." (11 151 lignes, genre/nombre parfois dans DETAIL --
//     structure differente des gabarits ci-dessus, lien seul, DETAIL jamais retouche)
// Les 76 restantes (0,04%, glose source deja malformee -- ex. parenthese jamais fermee,
// fragment tronque) restent totalement INCHANGEES plutot que de risquer une reconstruction
// fautive -- meme garde-fou applique au format 1 si le TEMPS extrait contient un chiffre, une
// parenthese ou le mot "verbe" (signe de troncature), verifie sur l'ensemble des 140 724 avant
// d'ecrire cette regle.
$conjugationFallbackHtml = null;
if ($conjugationVerbFormHtml === null) {
    foreach ($senses->senses as $sense) {
        if ($sense['pos'] !== 'V' || $sense['source'] !== 'template') {
            continue;
        }

        $definition = $sense['definition'];

        if (preg_match(
            '/^Forme conjuguée du verbe (\S+) \((.+), (1re|2e|3e) personne du (singulier|pluriel)\)\.$/u',
            $definition,
            $m,
        ) === 1) {
            [, $lemma, $tensePhraseRaw, $ordinal, $number] = $m;

            if (preg_match('/[()0-9]|verbe/ui', $tensePhraseRaw) === 1 || mb_strlen($tensePhraseRaw) > 40) {
                // Glose source deja malformee (fragment tronque) -- laissee totalement
                // inchangee, jamais de reconstruction sur une base suspecte.
                break;
            }

            $personKey = ($ordinal === '1re' ? '1' : ($ordinal === '2e' ? '2' : '3'))
                . ($number === 'singulier' ? 's' : 'p');

            $conjugationFallbackHtml = $renderVerbFormPhrase(
                mb_strtoupper($lemma),
                mb_strtolower($lemma),
                mb_strtolower($tensePhraseRaw),
                $personLabels[$personKey] ?? null,
                crc32($page->normalized) % 4,
            );
        } elseif (preg_match('/^Forme conjuguée du verbe (\S+)\.$/u', $definition, $m) === 1) {
            $conjugationFallbackHtml = 'Forme conjuguée du verbe <a href="/mot/' . e(mb_strtolower($m[1])) . '">' . e(mb_strtoupper($m[1])) . '</a>.';
        } elseif (preg_match('/^Participe (.+) du verbe (\S+)\.$/u', $definition, $m) === 1) {
            [, $participleDetail, $lemma] = $m;
            $conjugationFallbackHtml = 'Participe ' . e($participleDetail) . ' du verbe <a href="/mot/' . e(mb_strtolower($lemma)) . '">' . e(mb_strtoupper($lemma)) . '</a>.';
        }

        break; // un seul sens pos=V/source=template par fiche (D-043), jamais plusieurs.
    }
}

// Cartes de definition (D-0XX) : une par sens, pos + genre (si nom) en etiquette, phrase de
// definition en dessous. $senses->senses est deja borne (SenseLookup::ROW_LIMIT), aucune
// pagination necessaire ici.
//
// Fusion des sens a texte identique (retour utilisateur, ex. QUIZOMADAIRES) : de nombreuses
// formes flechies sont a la fois nom ET adjectif (ex. "campagnards" = pluriel du nom ET de
// l'adjectif "campagnard") -- render_grammatical_template() produit alors la MEME phrase pour
// les deux sens (le gabarit ne varie pas selon le pos). Afficher deux cartes identiques cote a
// cote lit comme du contenu duplique ; on fusionne en UNE carte, etiquette combinee
// ("adjectif / nom"), plutot que de perdre l'un des deux sens en base.
$senseCards = [];
$cardIndexByDefinition = [];
foreach ($senses->senses as $sense) {
    $label = $posLabels[$sense['pos']] ?? $sense['pos'];
    if ($sense['pos'] === 'N' && $sense['gender'] !== null && isset($genderLabels[$sense['gender']])) {
        $label = mb_strtolower($label) . ' ' . $genderLabels[$sense['gender']];
    } else {
        $label = mb_strtolower($label);
    }

    $definitionKey = mb_strtolower(trim($sense['definition']));
    if (isset($cardIndexByDefinition[$definitionKey])) {
        $existing = $cardIndexByDefinition[$definitionKey];
        if (!in_array($label, $senseCards[$existing]['pos_labels'], true)) {
            $senseCards[$existing]['pos_labels'][] = $label;
        }
        continue;
    }

    $cardIndexByDefinition[$definitionKey] = count($senseCards);
    $senseCards[] = [
        'pos_labels' => [$label],
        'definition' => $sense['definition'],
        'pos' => $sense['pos'],
        'source' => $sense['source'],
        'html' => false,
    ];
}
foreach ($senseCards as &$card) {
    $card['pos_label'] = implode(' / ', $card['pos_labels']);
}
unset($card);

// D-0XX : la carte stockee en base pour une forme conjuguee (pos=V, source=template, D-043,
// jamais retouchee) porte l'ancienne phrase fixe -- remplacee ici par le texte varie calcule
// plus haut, en direct depuis Conjugation (donnee vivante) si disponible, sinon par le repli
// regex ci-dessus -- jamais un second passage d'ecriture sur storage/dictionary_fr.sqlite pour
// ce simple habillage cosmetique.
$verbFormCardReplaced = false;
if ($conjugationVerbFormHtml !== null || $conjugationFallbackHtml !== null) {
    $replacementHtml = $conjugationVerbFormHtml ?? $conjugationFallbackHtml;

    foreach ($senseCards as &$card) {
        if ($card['pos'] === 'V' && $card['source'] === 'template') {
            $card['definition'] = $replacementHtml;
            $card['html'] = true;
            $verbFormCardReplaced = true;
            break;
        }
    }
    unset($card);

    // Mot francais non admis, forme conjuguee mais AUCUNE fiche word_senses (pilote D-043
    // limite aux mots admis + complement kaikki D-052 -- 435 120 formes anterieures a D-051
    // toujours sans aucun sens, ex. ABADAIENT) : aucune carte a remplacer ci-dessus, on
    // l'ajoute directement plutot que de perdre l'information. $conjugationFallbackHtml exige
    // deja une carte pos=V/template existante (il boucle sur $senses->senses pour la trouver),
    // donc n'atteint jamais cette branche -- seul $conjugationVerbFormHtml (donnee vivante
    // Conjugation) peut necessiter une carte synthetisee de toutes pieces.
    if (!$verbFormCardReplaced) {
        $senseCards[] = [
            'pos_labels' => ['verbe'],
            'pos_label' => 'verbe',
            'definition' => $replacementHtml,
            'html' => true,
        ];
    }
}

// asLemma : regroupe par temps (comme anagramsPlusOne regroupe par lettre ajoutee, meme
// composant .word-stream) puis par forme -- deux personnes homographes (ex. "pose" pour
// 1s ET 3s) partagent la meme cible /mot/pose et ne doivent donc apparaitre qu'une fois
// dans le flux ; les personnes concernees restent visibles au survol (title).
$conjugationLemmaGroups = [];
foreach ($conjugation->asLemma as $formEntry) {
    $tense = $formEntry['tense'];
    $slug = $formEntry['slug'];

    if (!isset($conjugationLemmaGroups[$tense][$slug])) {
        $conjugationLemmaGroups[$tense][$slug] = ['form' => $formEntry['form'], 'slug' => $slug, 'persons' => []];
    }

    if ($formEntry['person'] !== null) {
        $conjugationLemmaGroups[$tense][$slug]['persons'][] = $formEntry['person'];
    }
}
foreach ($conjugationLemmaGroups as &$groupsBySlug) {
    foreach ($groupsBySlug as &$group) {
        usort($group['persons'], static fn (string $a, string $b): int => ($personRank[$a] ?? 99) <=> ($personRank[$b] ?? 99));
    }
    unset($group);
}
unset($groupsBySlug);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="<?= e($seo->robots) ?>">
<title><?= e($statusMeta['title']) ?> | WORD CHECKR</title>
<meta name="description" content="<?= e($statusMeta['direct']) ?>">
<?php if ($seo->canonicalUrl !== null): ?>
<link rel="canonical" href="<?= e($seo->canonicalUrl) ?>">
<?php endif; ?>
<link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="shortcut icon" href="/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="WordCheckr">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<a class="skip-link" href="#main">Aller au contenu</a>
<header class="header">
  <div class="site header-row">
    <a class="logo" href="/"><img class="logo-mark" src="/assets/img/logo.png" alt="" width="32" height="32">WORD CHECKR</a>
    <nav class="nav" aria-label="Navigation principale"><a href="/">Nouvelle recherche</a></nav>
  </div>
</header>

<main class="word-shell main" id="main">
  <nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="/">Accueil</a> › Mot <?= e($page->normalized) ?></nav>

  <article class="word-card">
    <section class="word-answer">
      <span class="status-badge status-badge--<?= e($statusMeta['modifier']) ?>"><?= e($statusMeta['badge']) ?></span>
      <h1 class="word-title"><?= e($page->normalized) ?></h1>
      <p><?= e($statusMeta['subtitle']) ?></p>
      <div class="edition-badges">
        <span class="edition-badge <?= $page->isOds8 ? 'active ods8' : 'inactive' ?>">ODS8</span>
        <span class="edition-badge <?= $page->isOds9 ? 'active ods9' : 'inactive' ?>">ODS9</span>
      </div>
    </section>

    <section class="facts">
      <div class="fact">
        <strong><?= e($page->score) ?></strong>
        <span>Points Hors Bonus</span>
      </div>
      <div class="fact">
        <strong><?= e($page->length) ?></strong>
        <span>Lettres</span>
      </div>
      <div class="fact fact-letters">
        <div class="letter-tiles" role="img" aria-label="<?= e($tilesAriaLabel) ?>">
<?php foreach ($page->letters as $tile): ?>
          <span class="letter-tile" aria-hidden="true"><?= e($tile['letter']) ?><small><?= e($tile['value']) ?></small></span>
<?php endforeach; ?>
        </div>
        <span>Lettres Utilisées</span>
      </div>
    </section>

    <section class="direct">
      <h2>Réponse Directe</h2>
      <p><?= e($statusMeta['direct']) ?></p>
<?php if ($posLine !== null): ?>
      <div class="sense-card">
        <p class="sense-meta"><span class="sense-label">Nature Grammaticale</span> <span class="sense-pos"><?= e($posLine) ?></span></p>
      </div>
<?php endif; ?>
    </section>

<?php if ($senseCards !== []): ?>
    <section class="word-senses">
      <h2 class="sr-only">Définition</h2>
<?php foreach ($senseCards as $card): ?>
      <div class="sense-card">
        <p class="sense-meta"><span class="sense-label">Définition</span> <span class="sense-pos"><?= e($card['pos_label']) ?></span></p>
        <p class="sense-text"><?= $card['html'] ? $card['definition'] : e($card['definition']) ?></p>
      </div>
<?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($conjugation->asLemma !== []): ?>
    <section class="conjugation">
      <h2>Se Conjugue</h2>
      <p class="word-stream">
<?php foreach ($tenseOrder as $tenseKey): ?>
<?php if (!isset($conjugationLemmaGroups[$tenseKey])): continue; endif; ?>
<span class="word-text"><span class="plus"><?= e($tenseLabels[$tenseKey]) ?></span></span> <?php foreach ($conjugationLemmaGroups[$tenseKey] as $group): ?><a href="/mot/<?= e($group['slug']) ?>"<?php if ($group['persons'] !== []): ?> title="<?= e(implode(' / ', array_map(static fn (string $p): string => $personLabels[$p] ?? $p, $group['persons']))) ?>"<?php endif; ?>><?= e($group['form']) ?></a> <?php endforeach; ?>
<?php endforeach; ?>
      </p>
    </section>
<?php endif; ?>

<?php if ($relations !== null): ?>
    <section class="relations">
      <h2 class="relations-title">Jouer Autour De <?= e($page->normalized) ?></h2>
      <p class="relations-intro">Seules les catégories utiles sont affichées. La partie conservée ou modifiée est légèrement surlignée.</p>
      <div class="relation-grid">
<?php foreach ($relationCategories as $category): ?>
<?php if ($category['items'] === []): continue; endif; ?>
        <section class="relation<?= $category['full'] ? ' full' : '' ?>">
          <h3><span><?= e($category['title']) ?></span><span class="relation-count"><?= e($category['count']) ?></span></h3>
          <p class="word-stream">
<?php if (isset($category['groups'])): ?>
<?php foreach ($category['groups'] as $letter => $groupItems): ?>
<span class="word-text"><span class="plus">+<?= e($letter) ?></span></span> <?php foreach ($groupItems as $item): ?><a href="/mot/<?= e($item['slug']) ?>"><?= e($item['normalized']) ?></a> <?php endforeach; ?>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($category['items'] as $item): ?><a href="/mot/<?= e($item['slug']) ?>"><?= $highlighted($item, $category['key'], $page->normalized) ?></a> <?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($category['moreUrl'])): ?><a class="more-link" href="<?= e($category['moreUrl']) ?>"><?= e($category['moreLabel']) ?></a><?php endif; ?>
          </p>
        </section>
<?php endforeach; ?>
      </div>
    </section>

<?php if ($relations->relatedSearches !== []): ?>
    <section class="related">
      <h2>Recherches Liées</h2>
      <div class="related-links">
<?php foreach ($relations->relatedSearches as $link): ?>
        <a href="<?= e($link['url']) ?>"><?= e($relatedLabel($link, $page->normalized)) ?></a>
<?php endforeach; ?>
      </div>
    </section>
<?php endif; ?>
<?php endif; ?>

    <nav class="word-nav" aria-label="Navigation alphabétique">
<?php if ($page->previousWord !== null): ?>
      <a href="/mot/<?= e(strtolower($page->previousWord)) ?>">← <?= e($page->previousWord) ?></a>
<?php else: ?>
      <span></span>
<?php endif; ?>
<?php if ($page->nextWord !== null): ?>
      <a href="/mot/<?= e(strtolower($page->nextWord)) ?>"><?= e($page->nextWord) ?> →</a>
<?php else: ?>
      <span></span>
<?php endif; ?>
    </nav>

    <form class="inline-check" action="/verifier" method="get">
      <label class="sr-only" for="mot-check">Vérifier un autre mot</label>
      <input class="field" type="text" id="mot-check" name="mot" maxlength="15" autocomplete="off" spellcheck="false" placeholder="Vérifier un autre mot">
      <button class="btn btn-primary" type="submit">Vérifier</button>
    </form>
  </article>
</main>

<footer class="footer">
  <div class="word-shell footer-row">
    <span>Outil indépendant d’aide aux jeux de lettres.</span>
    <span class="footer-links"><a href="/mentions-legales">Mentions Légales</a> · <a href="/confidentialite">Confidentialité</a> · <a href="/contact">Contact</a></span>
  </div>
</footer>
</body>
</html>
