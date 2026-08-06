<?php

declare(strict_types=1);

/**
 * Front controller unique, sans framework (CLAUDE.md).
 *
 * Routes livrees en Phase 1 (docs/08) :
 *   GET /                pas de requete SQLite
 *   GET /mot/{mot}        jusqu'a 3 requetes indexees (App\Search\TermLookup::find())
 *   GET /verifier/{mot}   redirection pure vers /mot/{slug}, 0 requete SQLite
 *   GET /verifier?mot=..  meme redirection, entree par formulaire GET sans JavaScript
 *
 * Route ajoutee en Phase 2 (docs/08, agent data-engine) :
 *   GET /jouer/{lettres}  au plus 10 requetes indexees (App\Search\RackSolver::solve()),
 *                         0 requete si le plafond de securite est declenche
 *                         (voir reports/query-plans/phase2.md)
 *   GET /jouer?lettres=.. redirection pure vers /jouer/{slug}, 0 requete SQLite --
 *                         ajoute par l'agent frontend (rendu du solveur, Phase 2 UI)
 *                         pour que le formulaire chevalet de la home fonctionne sans
 *                         JavaScript, meme principe que /verifier?mot=.. ci-dessus.
 *                         Fichier partage (CLAUDE.md) : ajout signale pour validation.
 *
 * Route ajoutee en Phase 3 (docs/08, agent data-engine) :
 *   GET /mots/...          listes de mots par longueur, commencant, contenant, terminant,
 *                          avec, sans, motif, seules ou combinees dans l'ordre canonique
 *                          (docs/05) -- App\Search\WordListSolver::solve(), au plus 2
 *                          requetes indexees (reports/query-plans/phase3.md). Toute
 *                          permutation non canonique redirige en 301 vers la forme imposee
 *                          (App\Search\WordListFilters::canonicalPath()).
 *
 * Route ajoutee en Phase 5 (docs/08, agent data-engine) :
 *   GET /api/suggest?q=..  autocompletion, backend seul (une combobox cote frontend consomme
 *                          cette route dans un lot ulterieur) -- App\Search\Suggester::suggest(),
 *                          exactement 1 requete indexee (reports/query-plans/phase5.md), jamais
 *                          plus de 8 entrees, jamais d'erreur HTTP (entree vide, trop courte ou
 *                          hors A-Z -> tableau JSON vide, code 200). Reponse JSON pure, jamais de
 *                          vue app/View/ -- endpoint volontairement non indexable : aucun lien
 *                          HTML ne pointe vers cette route ailleurs sur le site.
 *
 * Enrichissement D-018 (docs/DECISIONS.md, agent data-engine) : /mot/{mot} passe desormais
 *   'conjugation' (App\Search\Conjugation) a la vue, en plus de 'page'/'relations' -- +1
 *   requete indexee (App\Search\ConjugationLookup::find()), pour tout mot TROUVE, admis ou
 *   non (independant du statut Scrabble). TermPage porte aussi pos/posSecondary/gender
 *   (colonnes ajoutees a `terms`, zero requete supplementaire, deja incluses dans le SELECT
 *   de TermLookup). Budget par fiche : 9 requetes pour un mot admis, 4 pour un mot francais
 *   non admis (reports/query-plans/d018-conjugation.md).
 *
 * Repli additif (Phase 2, refonte du champ unique de la home, agent frontend) :
 *   GET /verifier?q=..    accepte en plus de ?mot=.. (non supprime), meme redirection
 *   GET /jouer?q=..       accepte en plus de ?lettres=.. (non supprime), meme redirection
 *                         Necessaire car un seul <input> HTML ne peut porter qu'un nom :
 *                         la home n'a plus qu'un champ commun aux deux boutons submit
 *                         (formaction differents vers /verifier et /jouer). "mot" et
 *                         "lettres" restent lus en priorite, "q" est un repli, jamais
 *                         un remplacement -- aucune route existante n'est modifiee.
 *                         Fichier partage (CLAUDE.md) : ajout signale pour validation.
 *
 * Etat d'erreur visible (audit final, design-consistency-reviewer, bloquant F3) : toute
 *   saisie invalide sur /verifier, /jouer ou le formulaire GET de /mots redirige desormais
 *   vers /?erreur=1 ou /mots?erreur=1 plutot que vers l'URL nue -- "erreur" est un simple
 *   indicateur de presentation lu par app/View/home.php et app/View/explore-hub.php pour
 *   afficher un bandeau ARIA role="alert" (WCAG 3.3.1), jamais une URL indexable distincte
 *   (canonicalPath reste "/" ou "/mots" dans les deux cas, voir $render).
 *
 * Contrat de rendu : chaque route prepare un nom de vue et un tableau de donnees, puis
 * inclut app/View/{vue}.php (livre par l'agent frontend, Phase 1b) en exposant ce
 * tableau comme variables locales. Tant que app/View/ n'existe pas, un message texte
 * explicite remplace la vue plutot qu'une erreur fatale -- permet de tester le routage
 * et les donnees des maintenant.
 *
 * Fichier partage (CLAUDE.md) : cree ici a la demande explicite de la Phase 1 (docs/08),
 * signale pour validation avant edition future par un autre agent.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Config;
use App\Database\Connection;
use App\Search\ConjugationLookup;
use App\Search\ExploreHubBuilder;
use App\Search\Normalizer;
use App\Search\Rack;
use App\Search\RackSolver;
use App\Search\RelationsFinder;
use App\Search\Suggester;
use App\Search\TermLookup;
use App\Search\TermPage;
use App\Search\WordListFilters;
use App\Search\WordListSolver;
use App\Seo\Registry;
use App\Seo\SeoMeta;

const MAX_RAW_SEGMENT_LENGTH = 64;
// /mots/... peut enchainer plusieurs contraintes (longueur, commencant, contenant,
// terminant, avec, sans, motif -- voir docs/05), donc plusieurs segments : la meme borne
// de 64 par segment individuel serait trop stricte pour la combinaison complete, mais le
// chemin dans son ensemble reste borne pour ecarter tout abus (Phase 3, agent data-engine).
const MAX_RAW_WORDLIST_PATH_LENGTH = 512;

header('Content-Type: text/html; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '405 Methode non autorisee';

    return;
}

$config = Config::load(getenv('SCRABBLE_SITE') ?: 'fr');
$seoRegistry = new Registry($config->seoPath, $config->canonicalBaseUrl);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rawurldecode($path);

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

/**
 * Rend une vue via app/View/{name}.php, ou un message d'attente si elle n'existe pas
 * encore. $data est expose comme variables locales dans le fichier de vue.
 *
 * $canonicalPath, quand fourni, doit deja etre la forme canonique exacte servie pour cette
 * requete (Phase 6, agent seo-registry) -- resolue via le registre SEO en une requete
 * indexee sur storage/seo_fr.sqlite, jamais une erreur si la base ou la ligne est absente
 * (App\Seo\Registry::resolve(), noindex,follow par defaut, D-005). null pour les reponses
 * qui n'ont pas d'URL canonique propre (404, JSON) -- la vue omet alors <link rel="canonical">.
 *
 * @param array<string, mixed> $data
 */
$render = static function (string $view, array $data, int $status = 200, ?string $canonicalPath = null) use ($seoRegistry): void {
    http_response_code($status);

    $file = __DIR__ . '/../app/View/' . $view . '.php';

    if (!is_file($file)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Vue non implementee : {$view}\n";
        echo "Donnees pretes, en attente du gabarit (Phase 1b, agent frontend).\n";

        return;
    }

    $data['seo'] = $canonicalPath !== null ? $seoRegistry->resolve($canonicalPath) : SeoMeta::noindex(null);

    (static function (string $__file, array $__data): void {
        extract($__data, EXTR_SKIP);
        require $__file;
    })($file, $data);
};

$redirect = static function (string $location, int $status): void {
    http_response_code($status);
    header('Location: ' . $location);
};

if ($path === '/') {
    $render('home', [
        'minTermLength' => $config->minTermLength,
        'maxTermLength' => $config->maxTermLength,
        'error' => isset($_GET['erreur']),
    ], 200, '/');

    return;
}

if ($path === '/api/suggest') {
    // Phase 5 (docs/08, agent data-engine) : JSON pur, jamais de vue app/View/, jamais
    // d'erreur HTTP -- entree vide/trop courte/hors A-Z -> tableau vide, code 200. Remplace
    // le Content-Type text/html defini plus haut : aucune sortie n'a encore ete envoyee a ce
    // stade, header() peut donc encore le remplacer sans avertissement.
    header('Content-Type: application/json; charset=utf-8');

    $raw = $_GET['q'] ?? '';
    $raw = is_string($raw) ? $raw : '';

    $suggestions = strlen($raw) > MAX_RAW_SEGMENT_LENGTH
        ? []
        : (new Suggester(new Connection($config->dictionaryPath)))->suggest($raw);

    echo json_encode($suggestions, JSON_UNESCAPED_UNICODE);

    return;
}

if (preg_match('#^/mot/([^/]+)$#u', $path, $matches) === 1) {
    $segment = $matches[1];

    if ($segment === '' || strlen($segment) > MAX_RAW_SEGMENT_LENGTH) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    $connection = new Connection($config->dictionaryPath);
    $lookup = new TermLookup($connection, $config->tileScores);
    $page = $lookup->find($segment);

    if ($page === null) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    if ($segment !== $page->slug) {
        $redirect('/mot/' . $page->slug, 301);

        return;
    }

    // Relations (Phase 4, docs/08, agent data-engine) : 5 requetes indexees
    // supplementaires (App\Search\RelationsFinder), uniquement pour un mot
    // effectivement admis -- jamais calculees pour "francais non admis" ou
    // "inconnu", ou elles n'auraient aucun sens produit. null sinon, la vue
    // ne rend alors aucune section de relations (voir reports/query-plans/phase4.md).
    $relations = $page->status === TermPage::STATUS_ADMITTED
        ? (new RelationsFinder($connection))->find($page->normalized)
        : null;

    // Conjugaison (D-018) : 1 requete indexee supplementaire (App\Search\ConjugationLookup),
    // pour tout mot TROUVE, admis ou non -- independant du statut Scrabble (docs/DECISIONS.md,
    // D-018). Jamais pour "inconnu" ($page est toujours trouve a ce point du routeur).
    $conjugation = (new ConjugationLookup($connection))->find($page->normalized);

    $render(
        'word',
        ['page' => $page, 'relations' => $relations, 'conjugation' => $conjugation],
        200,
        '/mot/' . $page->slug,
    );

    return;
}

if ($path === '/jouer') {
    // Repli formulaire GET sans JavaScript (home, agent frontend, Phase 2) --
    // meme principe que /verifier?mot=.. juste au-dessus : 0 requete SQLite,
    // redirection pure vers la forme canonique /jouer/{slug} (App\Search\Rack,
    // deja construite par le solveur, reutilisee ici sans le lancer).
    //
    // Fichier partage (CLAUDE.md) : repli supplementaire ajoute par l'agent frontend
    // pour la refonte du champ unique de la home (un seul <input name="q">, deux
    // boutons submit avec des formaction differents vers /verifier et /jouer -- deux
    // noms de champ sur un seul input HTML sont impossibles). "lettres" reste lu en
    // premier et continue de fonctionner seul (repli existant, non supprime) ; "q"
    // n'est qu'un second nom accepte, additif uniquement.
    $raw = $_GET['lettres'] ?? ($_GET['q'] ?? '');
    $raw = is_string($raw) ? trim($raw) : '';

    if ($raw === '' || strlen($raw) > MAX_RAW_SEGMENT_LENGTH) {
        // F3 (audit final, design-consistency-reviewer) : silencieusement revenir sur "/"
        // sans indication laissait l'utilisateur face a un formulaire vide, sans savoir
        // que sa saisie avait ete rejetee (WCAG 3.3.1). "erreur" est un simple indicateur
        // de presentation, consomme uniquement par app/View/home.php -- jamais une URL
        // indexable distincte (canonicalPath de "/" reste "/", voir $render ci-dessus).
        $redirect('/?erreur=1', 302);

        return;
    }

    $rack = Rack::fromInput($raw);

    if ($rack === null) {
        $redirect('/?erreur=1', 302);

        return;
    }

    $redirect('/jouer/' . $rack->slug, 302);

    return;
}

if (preg_match('#^/jouer/([^/]+)$#u', $path, $matches) === 1) {
    $segment = $matches[1];

    if ($segment === '' || strlen($segment) > MAX_RAW_SEGMENT_LENGTH) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    $connection = new Connection($config->dictionaryPath);
    $solver = new RackSolver($connection);
    $page = $solver->solve($segment);

    if ($page === null) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    if ($segment !== $page->slug) {
        $redirect('/jouer/' . $page->slug, 301);

        return;
    }

    // tileScores : les tuiles du chevalet n'affichaient pas leur valeur en points,
    // contrairement a la fiche mot (audit final, C3) -- meme table que partout ailleurs
    // (config/sites/fr.php), jamais recalculee.
    $render('play', ['page' => $page, 'tileScores' => $config->tileScores], 200, '/jouer/' . $page->slug);

    return;
}

if ($path === '/mots' || preg_match('#^/mots(/.*)$#u', $path, $matches) === 1) {
    // Route ajoutee en Phase 3 (docs/08, agent data-engine) : listes de mots par longueur,
    // commencant, contenant, terminant, avec, sans, motif -- App\Search\WordListSolver::solve(),
    // au plus 2 requetes indexees (voir reports/query-plans/phase3.md). Fichier partage
    // (CLAUDE.md) : ajout signale pour validation, meme convention que les routes precedentes.
    $rest = $matches[1] ?? '';

    // Repli formulaire GET sans JavaScript pour le constructeur de contraintes de la home
    // (app/View/home.php, rapprochement de prototype/index.html) et l'outil "contenant" de la
    // page hub /mots (App\Search\ExploreHub ci-dessous) : 0 requete SQLite, redirection pure
    // vers la forme canonique -- meme principe que /verifier?mot=.. et /jouer?lettres=...
    // Chaque champ texte devient un segment de chemin ; "avec"/"sans" eclatent leur valeur en
    // un segment par lettre (App\Search\WordListFilters::readLetterMultiset() n'accepte qu'une
    // lettre par segment). Un champ absent ou vide n'ajoute aucun segment. "contenant",
    // "avec", "sans", "motif" restent volontairement hors sitemap/index (combinaisons infinies,
    // App\Seo\Family::NEVER_SITEMAP) : ce formulaire est un outil, jamais une liste de pages
    // pre-generees.
    if ($rest === '' && $_GET !== []) {
        $field = static function (string $name) use ($config): string {
            $raw = $_GET[$name] ?? '';
            $raw = is_string($raw) ? trim($raw) : '';

            return strlen($raw) > MAX_RAW_SEGMENT_LENGTH ? '' : $raw;
        };

        $segments = [];

        $length = $field('longueur');
        if ($length !== '' && ctype_digit($length)) {
            $segments[] = $length . '-lettres';
        }

        $commencant = $field('commencant');
        if ($commencant !== '') {
            $segments[] = 'commencant/' . $commencant;
        }

        $contenant = $field('contenant');
        if ($contenant !== '') {
            $segments[] = 'contenant/' . $contenant;
        }

        $terminant = $field('terminant');
        if ($terminant !== '') {
            $segments[] = 'terminant/' . $terminant;
        }

        $avec = $field('avec');
        if ($avec !== '') {
            $segments[] = 'avec/' . implode('/', mb_str_split($avec));
        }

        $sans = $field('sans');
        if ($sans !== '') {
            $segments[] = 'sans/' . implode('/', mb_str_split($sans));
        }

        $motif = $field('motif');
        if ($motif !== '') {
            $segments[] = 'motif/' . $motif;
        }

        if ($segments !== []) {
            $formFilters = WordListFilters::fromPath(implode('/', $segments));

            // F3 (audit final, design-consistency-reviewer) : une combinaison de contraintes
            // invalide (ex. longueur hors plage) revenait silencieusement sur /mots, sans que
            // l'utilisateur sache que sa saisie avait ete rejetee. "erreur" reste un indicateur
            // de presentation pur, consomme uniquement par app/View/explore-hub.php.
            $redirect($formFilters !== null ? '/mots/' . $formFilters->canonicalPath() : '/mots?erreur=1', 302);

            return;
        }
    }

    if (strlen($rest) > MAX_RAW_WORDLIST_PATH_LENGTH) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    // Page hub /mots (audit SEO final, seo-technical-auditor, C4 : les pages /mots/... sans
    // aucun lien entrant depuis le site) : trois grilles completes vers les familles deja
    // indexees (longueur, commencant, terminant -- 66 liens au total, D-017), construites
    // depuis App\Search\ExploreHub, jamais un GROUP BY au runtime (voir sa propre entete pour
    // la mesure qui l'impose). Remplace le 404 que WordListSolver::solve('') aurait renvoye.
    $connection = new Connection($config->dictionaryPath);

    if ($rest === '') {
        $hub = (new ExploreHubBuilder($connection))->build();

        $render('explore-hub', ['hub' => $hub, 'error' => isset($_GET['erreur'])], 200, '/mots');

        return;
    }

    $solver = new WordListSolver($connection);
    $page = $solver->solve($rest);

    if ($page === null) {
        $render('not-found', ['requestPath' => $path], 404);

        return;
    }

    $canonical = $page->canonicalPath === '' ? '/mots' : '/mots/' . $page->canonicalPath;
    $canonical .= $page->page > 1 ? '/page/' . $page->page : '';

    if ($path !== $canonical) {
        $redirect($canonical, 301);

        return;
    }

    $render('word-list', ['page' => $page], 200, $canonical);

    return;
}

if ($path === '/verifier' || preg_match('#^/verifier/([^/]*)$#u', $path, $matches) === 1) {
    // Fichier partage (CLAUDE.md) : "q" ajoute par l'agent frontend, meme raison et
    // meme convention que le repli "q" de /jouer juste au-dessus -- "mot" reste lu en
    // premier et continue de fonctionner seul (repli existant, non supprime).
    $raw = $matches[1] ?? ($_GET['mot'] ?? ($_GET['q'] ?? ''));
    $raw = is_string($raw) ? trim($raw) : '';

    if ($raw === '' || strlen($raw) > MAX_RAW_SEGMENT_LENGTH) {
        // F3 : voir le commentaire equivalent sur /jouer ci-dessus, meme raison.
        $redirect('/?erreur=1', 302);

        return;
    }

    $normalized = Normalizer::normalize($raw);

    if (!Normalizer::isValid($normalized)) {
        $redirect('/?erreur=1', 302);

        return;
    }

    $redirect('/mot/' . strtolower($normalized), 302);

    return;
}

$render('not-found', ['requestPath' => $path], 404);
