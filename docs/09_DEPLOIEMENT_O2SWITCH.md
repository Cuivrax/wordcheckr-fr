# Déploiement o2switch — checklist manuelle

Procédure spécifique à Scrabble Light FR, adaptée de la checklist utilisée pour un projet
voisin (Word Validator / motvalide.fr, Next.js) hébergé sur le même type de compte o2switch.
Nécessite un accès SSH/cPanel et doit être exécutée manuellement — cette session n'a pas ces
accès et ne peut pas déployer à la place de l'utilisateur.

## Différence structurante avec le projet de référence

Le projet de référence est du Node.js (Passenger, `server.js`, module natif `better-sqlite3`
bloqué par une version glibc trop ancienne sur o2switch, contournée via `node:sqlite`). Scrabble
Light FR est du **PHP 8.4 pur** (CLAUDE.md, D-001) : aucun de ces problèmes ne s'applique. Pas de
build, pas de process Node à faire tourner, pas de dépendance native. o2switch supporte PHP
nativement via cPanel (MultiPHP Manager) — le déploiement se résume à poser des fichiers et
configurer un document root.

## Prérequis cPanel

```text
PHP 8.4 sélectionné pour le domaine (cPanel → MultiPHP Manager)
extensions actives : pdo_sqlite, sqlite3, mbstring, intl (mêmes que l'environnement de dev,
  voir CLAUDE.md / reports/query-plans/phase0.md pour la liste vérifiée)
mod_rewrite actif (Apache) -- requis par public/.htaccess, actif par défaut sur o2switch
domaine www.wordcheckr.fr ajouté au compte cPanel (ou sous-domaine/addon domain selon l'offre)
```

## Structure sur le serveur

```text
/home/{user}/wordcheckr-fr/          racine du dépôt, HORS document root
  app/  config/  public/  scripts/  storage/  tests/  ...
/home/{user}/wordcheckr-fr/public/   <-- document root du domaine (cPanel → Domains)
```

`app/Config.php` résout `dictionary_path`/`seo_path` via `__DIR__ . '/../../storage/...'`
(chemins relatifs, `config/sites/fr.php`) — tant que la structure du dépôt reste intacte avec
`storage/` au même niveau que `app/`/`public/`, aucun chemin en dur à changer. Seul le document
root du domaine doit pointer vers le sous-dossier `public/`, jamais vers la racine du dépôt —
c'est ce qui garde `app/`, `config/`, `storage/`, `scripts/`, `tests/`, `data/` hors d'accès web
(`public/.htaccess` le documente déjà, non vérifiable en local, à confirmer une fois en ligne).

## Transfert du code

```text
option recommandée (comme le projet de référence) : git clone en SSH avec une clé de
  déploiement dédiée (GitHub → Settings → Deploy keys, lecture seule), sur la branche main
alternative : SFTP direct si le dépôt n'est pas sur un remote accessible depuis o2switch
```

## Transfert des bases SQLite -- séparé, PAS via git

```text
storage/ est gitignore (build artifact, D-007) : git clone n'apporte NI dictionary_fr.sqlite
  NI seo_fr.sqlite -- transfert SFTP/SCP obligatoire et distinct
storage/dictionary_fr.sqlite   262 Mo
storage/seo_fr.sqlite          604 Mo  (croît avec le registre -- 924 408 lignes actuellement)
total ~866 Mo -- prévoir le temps de transfert, et l'espace disque disponible sur le compte
  o2switch (vérifier le quota du plan)
destination : {racine du dépôt}/storage/ -- MÊME emplacement qu'en local, hors document root
```

## Variables d'environnement

```text
SCRABBLE_SITE=fr                        -- sélectionne config/sites/fr.php (App\Config::load())
SCRABBLE_CONTACT_EMAIL={adresse}         -- formulaire /contact (public/index.php:251), PHP
                                            mail() natif -- pas de SMTP/nodemailer nécessaire ici
                                            contrairement au projet de référence. Sans cette
                                            variable, /contact échoue explicitement plutôt que
                                            d'envoyer un mail mal formé (voir index.php:253-256)
```

À définir dans cPanel → MultiPHP INI Editor / Application Manager selon l'offre, ou dans un
`.env` chargé au bootstrap si ce mécanisme existe déjà côté app (à vérifier -- `app/Config.php`
ne lit actuellement `SCRABBLE_SITE`/`SCRABBLE_CONTACT_EMAIL` que via `getenv()`, donc la méthode
cPanel de définition de variables d'environnement pour l'app PHP suffit).

## DNS / SSL

```text
option simple (recommandée pour ce projet -- PHP+SQLite, pas de besoin de CDN/edge) :
  AutoSSL natif o2switch (Let's Encrypt via cPanel), DNS pointé directement chez le
  registrar de wordcheckr.fr vers les serveurs de noms o2switch
option Cloudflare (comme le projet de référence, si le protection DDoS/CDN devient
  pertinente vu le volume de pages -- 924 408 URL indexables) : DNS importé dans Cloudflare,
  proxifié, SSL/TLS en mode "Full" (jamais "Flexible" -- boucle de redirection avec un
  certificat déjà actif côté origine)
canonical_base_url déjà fixé à https://www.wordcheckr.fr (config/sites/fr.php, D-042) --
  s'assurer que le domaine effectivement configuré correspond exactement (www. inclus)
```

## Vérification post-déploiement

```text
routes témoins en 200 : /, /mot/{mot-connu}, /mots/7-lettres, /verifier/{mot}
lecture seule confirmée : storage/*.sqlite non accessible depuis une URL publique
  (https://www.wordcheckr.fr/storage/... doit répondre 403/404, jamais servir le fichier)
TTFB à chaud mesuré sur quelques routes (budget CLAUDE.md : p95 < 250 ms) -- premier vrai
  test sous l'hébergement mutualisé réel, jamais mesurable en local (D-019 le signale déjà
  comme suite à donner)
/contact : soumission de test, vérifier réception (dépend du MTA local o2switch -- risque de
  spam plus élevé qu'un relais SMTP authentifié, à surveiller, pas un correctif préalable)
sitemap-index.xml et les 36 fragments accessibles publiquement, avant toute soumission
  Search Console
```

## Après mise en ligne — soumission des sitemaps

Le dimensionnement des vagues de soumission (combien de fragments par vague, critère de
passage à la suivante) est traité séparément — voir `docs/DECISIONS.md` (D-041/D-042 et
suivants) et `reports/query-plans/` pour le plan chiffré une fois produit. Ne pas soumettre
les 36 fragments d'un coup sans ce séquencement.
