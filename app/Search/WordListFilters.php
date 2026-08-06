<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Analyse et canonicalise les contraintes de /mots/... (Phase 3, docs/08).
 *
 * Ordre canonique impose partout -- URL, cles, canonicals (docs/05_URL_SEO_INDEXATION.md) :
 *
 *   longueur -> commencant -> contenant -> terminant -> position -> avec -> sans -> motif
 *
 * "position" reste hors perimetre de cette phase : absent de la liste des contraintes a
 * couvrir (docs/08), absent de tous les exemples de route. Un segment "position" rencontre
 * dans une URL est traite comme un mot-cle inconnu -> fromPath() renvoie null -> 404, pas un
 * 301 vers une forme corrigee. La place lui reste reservee dans l'ordre documente ci-dessus
 * pour que canonicalPath() reste correct si la contrainte est ajoutee plus tard.
 *
 * Cette classe ne fait AUCUN acces base : parsing et validation syntaxique pures, meme
 * discipline que Rack::fromInput(). WordListSolver traduit ensuite ces filtres en requetes.
 *
 * Canonicalisation : quel que soit l'ordre des mots-cles dans l'URL recue, fromPath()
 * reconstruit une representation interne normalisee, puis canonicalPath() la re-serialise
 * TOUJOURS dans l'ordre impose. Le routeur compare le chemin recu a canonicalPath() -- meme
 * convention que TermPage::$slug et RackPage::$slug -- et redirige en 301 si différent
 * ("toute autre permutation redirige en 301", docs/05).
 */
final readonly class WordListFilters
{
    /** Mots-cles reconnus, dans l'ordre canonique. "position" volontairement absent (hors perimetre). */
    private const array KEYWORDS = ['commencant', 'contenant', 'terminant', 'avec', 'sans', 'motif'];

    /**
     * @param int|null $length longueur exacte demandee, 2 a 15
     * @param string|null $prefix forme normalisee (A-Z), commencant
     * @param string|null $suffix forme normalisee (A-Z), terminant
     * @param string|null $contains forme normalisee (A-Z), contenant
     * @param array<string, int> $withLetters lettre normalisee => nombre minimum d'occurrences
     *        (avec, repetitions comptees), triees par cle
     * @param list<string> $withoutLetters lettres normalisees a exclure completement (sans),
     *        triees, sans doublon
     * @param string|null $pattern motif de cases connues : A-Z pour une lettre connue, '-'
     *        pour une case inconnue ; longueur du motif = longueur du mot (2 a 15)
     * @param int $page page demandee, >= 1 (1 = premiere page, jamais reflete dans l'URL)
     */
    private function __construct(
        public ?int $length,
        public ?string $prefix,
        public ?string $suffix,
        public ?string $contains,
        public array $withLetters,
        public array $withoutLetters,
        public ?string $pattern,
        public int $page,
    ) {
    }

    /**
     * Construit les filtres a partir du chemin brut recu par le routeur (deja debarrasse du
     * prefixe "/mots", ex. "/7-lettres/commencant/ch" ou "" pour /mots seul).
     *
     * Renvoie null pour toute forme non exploitable : mot-cle inconnu (dont "position", hors
     * perimetre), mot-cle duplique, valeur manquante ou invalide, "avec"/"sans" sans lettre,
     * longueur hors bornes, motif hors bornes ou incoherent. Aucune exception ne remonte --
     * meme discipline que Normalizer::normalize() et Rack::fromInput() : une entree
     * utilisateur ne doit jamais faire planter le flux HTTP normal. C'est une erreur de
     * saisie/routage, pas un resultat de recherche -- au routeur de traduire null en 404.
     */
    public static function fromPath(string $rawPath): ?self
    {
        $segments = array_values(array_filter(explode('/', trim($rawPath, '/')), static fn (string $s): bool => $s !== ''));

        [$page, $segments] = self::extractTrailingPage($segments);

        if ($page === null) {
            return null;
        }

        $length = null;
        $prefix = null;
        $suffix = null;
        $contains = null;
        $withLetters = [];
        $withoutLetters = [];
        $pattern = null;
        $seenKeywords = [];

        $i = 0;
        $count = count($segments);

        // La longueur, si presente, doit ouvrir la liste -- c'est un token positionnel
        // ("{N}-lettres"), pas un mot-cle suivi d'une valeur comme les autres.
        if ($count > 0 && preg_match('/^(\d{1,2})-lettres\z/', $segments[0], $m) === 1) {
            $length = (int) $m[1];

            if ($length < Normalizer::MIN_LENGTH || $length > Normalizer::MAX_LENGTH) {
                return null;
            }

            $i = 1;
        }

        while ($i < $count) {
            $keyword = $segments[$i];

            if (!in_array($keyword, self::KEYWORDS, true)) {
                // Inclut le cas "{N}-lettres" hors premiere position, et tout mot-cle
                // inconnu (dont "position", hors perimetre de cette phase) : 404, jamais 301.
                return null;
            }

            if (isset($seenKeywords[$keyword])) {
                return null;
            }
            $seenKeywords[$keyword] = true;

            $i++;

            switch ($keyword) {
                case 'commencant':
                    [$prefix, $i] = self::readSingleLetterRun($segments, $i, $count);
                    if ($prefix === null) {
                        return null;
                    }
                    break;

                case 'contenant':
                    [$contains, $i] = self::readSingleLetterRun($segments, $i, $count);
                    if ($contains === null) {
                        return null;
                    }
                    break;

                case 'terminant':
                    [$suffix, $i] = self::readSingleLetterRun($segments, $i, $count);
                    if ($suffix === null) {
                        return null;
                    }
                    break;

                case 'avec':
                    [$withLetters, $i] = self::readLetterMultiset($segments, $i, $count);
                    if ($withLetters === null) {
                        return null;
                    }
                    break;

                case 'sans':
                    [$withoutLetters, $i] = self::readLetterSet($segments, $i, $count);
                    if ($withoutLetters === null) {
                        return null;
                    }
                    break;

                case 'motif':
                    [$pattern, $i] = self::readPattern($segments, $i, $count);
                    if ($pattern === null) {
                        return null;
                    }
                    break;
            }
        }

        // Le motif implique sa propre longueur (position de chaque case). Une longueur
        // explicite differente n'est pas une erreur 404 : canonicalPath() fait toujours
        // primer la longueur du motif, et le routeur redirige en 301 vers la forme corrigee
        // -- meme esprit que "toute autre permutation redirige en 301".
        if ($pattern !== null) {
            $length = strlen($pattern);
        }

        // Aucune contrainte du tout ($length === null && ... && $pattern === null) reste un
        // etat valide : /mots seul = parcours complet, pagine (voir isEmpty()). Ce n'est pas
        // une route annoncee par docs/05 -- le routeur decide s'il l'expose.

        ksort($withLetters, SORT_STRING);
        sort($withoutLetters, SORT_STRING);

        return new self($length, $prefix, $suffix, $contains, $withLetters, $withoutLetters, $pattern, $page);
    }

    /**
     * Un seul segment lettres-uniquement (commencant / contenant / terminant). Renvoie
     * [null, $i] si absent, vide ou invalide.
     *
     * @param list<string> $segments
     * @return array{0: string|null, 1: int}
     */
    private static function readSingleLetterRun(array $segments, int $i, int $count): array
    {
        if ($i >= $count) {
            return [null, $i];
        }

        $normalized = Normalizer::normalize($segments[$i]);

        if ($normalized === '' || preg_match('/^[A-Z]+\z/', $normalized) !== 1 || strlen($normalized) > Normalizer::MAX_LENGTH) {
            return [null, $i];
        }

        return [$normalized, $i + 1];
    }

    /**
     * Une ou plusieurs cases-segments d'une seule lettre chacune (avec), consommees
     * jusqu'au prochain mot-cle connu ou la fin du chemin. Compte les repetitions.
     *
     * @param list<string> $segments
     * @return array{0: array<string, int>|null, 1: int}
     */
    private static function readLetterMultiset(array $segments, int $i, int $count): array
    {
        $letters = [];
        $start = $i;

        while ($i < $count && !in_array($segments[$i], self::KEYWORDS, true)) {
            $normalized = Normalizer::normalize($segments[$i]);

            if (strlen($normalized) !== 1 || preg_match('/^[A-Z]\z/', $normalized) !== 1) {
                return [null, $i];
            }

            $letters[$normalized] = ($letters[$normalized] ?? 0) + 1;
            $i++;
        }

        if ($i === $start) {
            // "avec" sans aucune lettre : segment vide ou immediatement suivi d'un mot-cle.
            return [null, $i];
        }

        return [$letters, $i];
    }

    /**
     * Une ou plusieurs cases-segments d'une seule lettre chacune (sans), dedupliquees.
     *
     * @param list<string> $segments
     * @return array{0: list<string>|null, 1: int}
     */
    private static function readLetterSet(array $segments, int $i, int $count): array
    {
        [$multiset, $next] = self::readLetterMultiset($segments, $i, $count);

        if ($multiset === null) {
            return [null, $next];
        }

        return [array_keys($multiset), $next];
    }

    /**
     * Le motif : un seul segment, lettres A-Z ou '-' (case inconnue), longueur 2 a 15,
     * au moins une lettre connue (un motif entierement fait de '-' n'apporte aucune
     * information au-dela de la longueur -- refuse pour rester un mot-cle utile).
     *
     * @param list<string> $segments
     * @return array{0: string|null, 1: int}
     */
    private static function readPattern(array $segments, int $i, int $count): array
    {
        if ($i >= $count) {
            return [null, $i];
        }

        $raw = $segments[$i];
        $letters = str_replace('-', '', $raw);
        $normalizedLetters = Normalizer::normalize($letters);

        if ($letters !== '' && (preg_match('/^[A-Z]+\z/', $normalizedLetters) !== 1 || strlen($normalizedLetters) !== strlen($letters))) {
            return [null, $i];
        }

        // Reconstruit le motif normalise en respectant la position d'origine des '-' : on ne
        // peut pas juste normaliser $raw tel quel, Normalizer::normalize() ne connait pas '-'.
        $pattern = '';
        $letterPos = 0;
        $normalizedChars = str_split($normalizedLetters);

        foreach (str_split($raw) as $char) {
            if ($char === '-') {
                $pattern .= '-';
                continue;
            }

            $pattern .= $normalizedChars[$letterPos] ?? '';
            $letterPos++;
        }

        if (strlen($pattern) < Normalizer::MIN_LENGTH || strlen($pattern) > Normalizer::MAX_LENGTH) {
            return [null, $i];
        }

        if ($letters === '') {
            // Motif entierement inconnu ("---") : refuse, n'apporte rien qu'une longueur ne
            // dise deja.
            return [null, $i];
        }

        return [$pattern, $i + 1];
    }

    /**
     * Detecte et retire un segment terminal "page/{n}". Absent -> page 1, segments
     * inchanges. "page/1" est syntaxiquement VALIDE (n'est pas une entree malformee) mais
     * jamais canonique -- canonicalUrl() l'omet toujours pour la page 1, donc le routeur
     * compare et redirige naturellement en 301 vers la forme sans "/page/1", meme mecanisme
     * que toute autre permutation (docs/05 : "toute autre permutation redirige en 301"), pas
     * un 404. Seule une valeur non numerique, 0 ou negative reste une entree malformee ->
     * [null, ...], propage vers fromPath() qui renvoie null (404).
     *
     * @param list<string> $segments
     * @return array{0: int|null, 1: list<string>}
     */
    private static function extractTrailingPage(array $segments): array
    {
        $count = count($segments);

        if ($count < 2 || $segments[$count - 2] !== 'page') {
            return [1, $segments];
        }

        if (preg_match('/^\d+\z/', $segments[$count - 1]) !== 1) {
            return [null, $segments];
        }

        $page = (int) $segments[$count - 1];

        if ($page < 1) {
            return [null, $segments];
        }

        return [$page, array_slice($segments, 0, $count - 2)];
    }

    /**
     * Chemin canonique, sans le "/mots" initial ni le "/page/{n}" final (la pagination est
     * geree separement par le routeur/la vue, pas par cette representation de filtre --
     * meme raison que "page 1" n'apparait jamais dans l'URL). Toujours reconstruit dans
     * l'ordre impose, quel que soit l'ordre recu en entree.
     */
    public function canonicalPath(): string
    {
        $segments = [];

        if ($this->length !== null) {
            $segments[] = $this->length . '-lettres';
        }

        if ($this->prefix !== null) {
            $segments[] = 'commencant';
            $segments[] = strtolower($this->prefix);
        }

        if ($this->contains !== null) {
            $segments[] = 'contenant';
            $segments[] = strtolower($this->contains);
        }

        if ($this->suffix !== null) {
            $segments[] = 'terminant';
            $segments[] = strtolower($this->suffix);
        }

        if ($this->withLetters !== []) {
            $segments[] = 'avec';
            foreach ($this->withLetters as $letter => $times) {
                for ($k = 0; $k < $times; $k++) {
                    $segments[] = strtolower($letter);
                }
            }
        }

        if ($this->withoutLetters !== []) {
            $segments[] = 'sans';
            foreach ($this->withoutLetters as $letter) {
                $segments[] = strtolower($letter);
            }
        }

        if ($this->pattern !== null) {
            $segments[] = 'motif';
            $segments[] = strtolower($this->pattern);
        }

        return implode('/', $segments);
    }

    /** Chemin canonique complet, "/page/{n}" inclus si $this->page > 1. */
    public function canonicalUrl(): string
    {
        $base = '/mots' . ($this->canonicalPath() !== '' ? '/' . $this->canonicalPath() : '');

        return $this->page > 1 ? $base . '/page/' . $this->page : $base;
    }

    /** true si le filtre ne pose aucune contrainte (parcours complet de la base). */
    public function isEmpty(): bool
    {
        return $this->length === null && $this->prefix === null && $this->suffix === null
            && $this->contains === null && $this->withLetters === [] && $this->withoutLetters === []
            && $this->pattern === null;
    }

    /**
     * true si des predicats non couverts par un index dedie sont necessaires (contenant,
     * avec, sans, ou motif avec une case connue au-dela du prefixe initial). Determine si
     * WordListSolver doit appliquer WordListSolver::ROW_EXAMINATION_CEILING (voir sa
     * documentation pour le detail des mesures qui justifient ce plafond).
     */
    public function needsUnindexedPredicates(): bool
    {
        if ($this->contains !== null || $this->withLetters !== [] || $this->withoutLetters !== []) {
            return true;
        }

        if ($this->pattern !== null) {
            // Le prefixe initial (avant le premier '-') est deja couvert par l'index
            // normalized/length -- voir WordListSolver::patternLeadingPrefix(). Une case
            // connue (A-Z) APRES la premiere case inconnue reste un predicat non indexe
            // (substr(normalized, position, 1) = lettre, evalue en ligne, pas via un index).
            $firstUnknown = strpos($this->pattern, '-');

            if ($firstUnknown === false) {
                // Pas de '-' du tout : readPattern() l'autorise (seul un motif ENTIEREMENT
                // fait de '-' est refuse) -- un motif entierement connu equivaut a
                // normalized = ?, couvert par l'index UNIQUE, aucun predicat supplementaire.
                return false;
            }

            return preg_match('/[A-Z]/', substr($this->pattern, $firstUnknown)) === 1;
        }

        // Prefixe ET suffixe combines : le suffixe est applique en predicat supplementaire
        // sur les lignes deja bornees par le prefixe (pas son propre index dans ce cas).
        if ($this->prefix !== null && $this->suffix !== null) {
            return true;
        }

        return false;
    }
}
