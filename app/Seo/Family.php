<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Liste fermée des familles de reporting/gouvernance du registre SEO (Phase 6, docs/08).
 *
 * Une famille correspond à un type de route, pas à une route individuelle — elle sert à :
 * - produire les métriques quantifiées exigées par lot (URL par famille) ;
 * - appliquer les règles dures par famille (ex. NEVER_SITEMAP ci-dessous), à la fois dans
 *   scripts/apply_seo_batch.php (refus à l'écriture) et dans les rapports de rollout.
 *
 * Correspondance avec les préfixes de fragments documentés (docs/05_URL_SEO_INDEXATION.md,
 * section Sitemaps) : words-*, invalid-french-*, starts-*, ends-*, contains-*, letters-*.
 * CORE n'est pas dans la liste documentée — extension minimale et délibérée pour couvrir la
 * home (aucune autre route statique connue à ce jour) ; signalée pour validation, pas ajoutée
 * silencieusement (voir rapport AFTER de l'agent seo-registry).
 */
final class Family
{
    public const string HOME = 'home';
    public const string WORD_ADMITTED = 'word_admitted';
    public const string WORD_FRENCH_NOT_ADMITTED = 'word_french_not_admitted';
    public const string WORD_LIST_LENGTH = 'word_list_length';
    public const string WORD_LIST_COMMENCANT = 'word_list_commencant';
    public const string WORD_LIST_TERMINANT = 'word_list_terminant';
    public const string WORD_LIST_CONTENANT = 'word_list_contenant';
    public const string WORD_LIST_AVEC = 'word_list_avec';
    public const string WORD_LIST_SANS = 'word_list_sans';
    public const string WORD_LIST_MOTIF = 'word_list_motif';
    public const string WORD_LIST_COMBINED = 'word_list_combined';
    public const string RACK = 'rack';

    /** @var list<string> */
    public const array ALL = [
        self::HOME,
        self::WORD_ADMITTED,
        self::WORD_FRENCH_NOT_ADMITTED,
        self::WORD_LIST_LENGTH,
        self::WORD_LIST_COMMENCANT,
        self::WORD_LIST_TERMINANT,
        self::WORD_LIST_CONTENANT,
        self::WORD_LIST_AVEC,
        self::WORD_LIST_SANS,
        self::WORD_LIST_MOTIF,
        self::WORD_LIST_COMBINED,
        self::RACK,
    ];

    /**
     * Familles dont l'espace d'URL est combinatoire, potentiellement non borné en pratique
     * (contenant/avec/sans/motif : toute sous-chaîne, tout multiensemble de lettres, toute
     * combinaison de cases connues — docs/05 n'en documente d'ailleurs aucun préfixe de
     * sitemap, contrairement à longueur/commençant/terminant). Contrainte dure du projet :
     * "Refuse infinite letter/sequence combinations as indexable by default." Ces familles ne
     * doivent JAMAIS recevoir de sitemap_fragment, quel que soit le lot — appliqué en dur par
     * scripts/apply_seo_batch.php, pas seulement documenté ici.
     *
     * RACK (/jouer/{lettres}) est logé à la même enseigne : un tirage est lui aussi une
     * combinaison quasi illimitée (15 caractères, jokers compris), et docs/05 ne documente
     * aucun fragment de sitemap pour cette route.
     *
     * @var list<string>
     */
    public const array NEVER_SITEMAP = [
        self::WORD_LIST_CONTENANT,
        self::WORD_LIST_AVEC,
        self::WORD_LIST_SANS,
        self::WORD_LIST_MOTIF,
        self::WORD_LIST_COMBINED,
        self::RACK,
    ];

    /**
     * Familles couvrant des formes françaises non retenues à l'ODS8/ODS9. Contrainte dure :
     * "Never propose indexing these in bulk" — tout lot touchant cette famille doit rester
     * petit et justifié individuellement, jamais un simple pourcentage des 435 120 lignes.
     * Appliqué comme un plafond dur (voir MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED) plutôt qu'une
     * simple note, pour qu'un lot mal dimensionné échoue à l'application, pas seulement à la
     * relecture humaine.
     *
     * @var list<string>
     */
    public const array FRENCH_NOT_ADMITTED = [
        self::WORD_FRENCH_NOT_ADMITTED,
    ];

    /**
     * Plafond appliqué par scripts/apply_seo_batch.php à tout lot touchant une famille de
     * FRENCH_NOT_ADMITTED. Révisé le 2026-08-04 (D-017, docs/DECISIONS.md) : la valeur initiale
     * de 50 avec attestation ligne par ligne empêchait structurellement de rendre les formes
     * françaises non admises trouvables via Google, alors que le site répond aux deux questions
     * "admis ?" et "non admis ?" et qu'un visiteur ne sait jamais laquelle s'applique avant de
     * chercher — décision explicite du propriétaire du produit, prise en connaissance du risque
     * SEO habituel de contenu peu différencié en volume, jugé acceptable ici car : (1) le site
     * n'est pas encore déployé (Phase 7 non commencée), donc rien de ce qui est écrit ici n'est
     * vu par le vrai Google avant une mise en ligne réelle et son propre séquençage par lots ;
     * (2) ces pages ne sont pas vides (badge, score, tuiles, réponse directe pour les trois
     * statuts, voir app/View/word.php) — seul le bloc de relations (Phase 4) leur manque.
     * L'attestation reste ligne par ligne dans le SCHEMA (R6, R7 : notes non vide obligatoire),
     * seul le plafond de VOLUME change. */
    public const int MAX_BATCH_SIZE_FRENCH_NOT_ADMITTED = 500_000;

    public static function isValid(string $family): bool
    {
        return in_array($family, self::ALL, true);
    }

    public static function forbidsSitemap(string $family): bool
    {
        return in_array($family, self::NEVER_SITEMAP, true);
    }

    public static function isFrenchNotAdmitted(string $family): bool
    {
        return in_array($family, self::FRENCH_NOT_ADMITTED, true);
    }
}
