# Déploiement et contrôles SEO hors débats et arguments

## Configuration relevée le 24 novembre 2026 (FR et EN)

Le serveur et le serveur canonique sont déjà en HTTPS sur le bon sous-domaine.
EnableCanonicalServerLink est activé. Au moment du relevé initial, MetadataGenerators contenait OpenGraph et
Twitter, mais pas SchemaOrg. L'administrateur a ensuite confirmé l'ajout
de SchemaOrg ; vérifier l'émission publique de JSON-LD après le déploiement. ShortDescriptionEnableTagline est actif ;
WikiSeoDefaultLanguage est nul. SitemapNamespaces est false et la politique
du namespace 3100 est noindex. Ne pas supposer que les autres wikis ont la même
configuration.

## Activation à intégrer au déploiement groupé, après mise à jour de WikiSEO

```php
$wgMetadataGenerators = [
	'OpenGraph',
	'Twitter',
	'SchemaOrg',
];
$wgWikiSeoWebsiteUrl = 'https://fr.wikidebates.org/';
$wgWikiSeoWebsiteName = 'Wikidébats';
$wgWikiSeoLargeImagePreview = true;
```

Adapter la racine et le nom à chaque wiki, conserver les autres générateurs
éventuellement configurés. Vérifier la racine publique et sa destination
canonique. Ne pas configurer le nom de l'éditeur ou son logo sans vérifier
son identité et l'URL officielle ; employer ensuite WikiSeoPublisher comme
documenté par WikiSEO. Les pages natives global-only conservent leur moteur.
Ne pas réactiver l'ancien hreflang ; le chantier Interlanguage est séparé.

## Sitemaps et robots.txt

Conserver le générateur MediaWiki. Choisir explicitement les espaces de noms
publics après inventaire (ne pas deviner les identifiants personnalisés).
Le générateur ignore les pages portant la propriété noindex et accepte
--skip-redirects. La politique NamespaceRobotPolicies, les règles WikiSEO
et les canoniques éditoriales ne sont pas automatiquement toutes reflétées.
Ne pas changer le cron, les fichiers XML ou robots.txt avant cet inventaire.
L'option --namespace-inventory de la sonde lit aussi l'identité des espaces
de noms déclarés par chacun des six wikis. Le marqueur content de siteinfo
est informatif : les pages de projet et d'aide peuvent être éditoriales sans
ce marqueur. Les identifiants personnalisés ne sont pas devinés.

### Politique des sitemaps validée par l'inventaire public

L'inventaire des six wikis a réussi le 24 novembre 2026 (HTTP 200).
Les espaces 0, 4 et 12 existent partout ; 3000 (Portail/Portal) est présent
sur FR et EN seulement. 3100 (Autoévaluation/Self assessment) ne doit pas
être soumis. Les attributs SMW (102), formulaires (106), concepts (108),
schémas (112), modules (828), sujets Flow (2600), utilisateurs, discussions,
fichiers, modèles et pages système sont hors sélection.

Les catégories (14) mélangent navigation éditoriale et maintenance cachée :
les retirer provisoirement du sitemap ne change ni leur indexabilité ni
leur accessibilité. Idem pour les fichiers et Google Images. Les pages
principales, de projet, d'aide et les portails restent soumises, sauf
redirections et exclusions natives __NOINDEX__.

Dans les configurations effectives, **après la détermination du wiki** :

FR/EN :

```php
$wgSitemapNamespaces = [
	NS_MAIN,
	NS_PROJECT,
	NS_HELP,
	3000, // Portail / Portal
];
```

DE/ES/IT/PT :

```php
$wgSitemapNamespaces = [
	NS_MAIN,
	NS_PROJECT,
	NS_HELP,
];
```

Si la configuration est partagée, choisir le tableau d'après le wiki
effectif et non d'après la langue de l'interface du visiteur. Vérifier
l'ensemble des NamespaceRobotPolicies avant de régénérer les sitemaps.
Ne pas utiliser wgContentNamespaces comme liste automatique : les attributs
SMW peuvent y être inclus, alors que l'accueil de certains wikis ne l'est pas.

### Régénération et validation

Conserver les chemins et identifiants du générateur natif actuellement
utilisés par le cron. Vérifier sa commande effective avant toute publication ;
un argument --namespaces écrase wgSitemapNamespaces. Ajouter --skip-redirects
s'il est absent, ou conserver cet argument s'il est déjà présent.

Tester la génération dans un répertoire temporaire non public, avec les
mêmes options --wiki, --identifier, --urlpath et --compress que le cron,
puis vérifier l'index avant de publier. Ne pas supprimer les anciens fichiers
de sitemap en bloc : ils peuvent encore être consultés par un moteur.
Ne pas modifier les URL publiques ou les règles de robots.txt dans ce lot.

Après publication, exécuter :

```sh
python3 tests/ci/publicSeoAudit.py --namespace-inventory --expect-sitemap-policy
```

Le code de sortie 1 indique un index inaccessible ou non conforme à la
sélection attendue. Les espaces autorisés mais vides ne nécessitent pas de
fichier. Le fichier de projet (4) est attendu car tous les accueils sont dans
cet espace. Ce contrôle ne vérifie pas chaque URL des sitemaps enfants.
Sans --expect-sitemap-policy, la sonde reste informative.

Privilégier un lien Sitemap vers l'index propre à chaque hôte. Vérifier que
les sitemaps et les ressources de rendu accessibles au public sont lisibles
par les robots autorisés. Ne pas retirer les interdictions anti-abus ou
anti-collecte sans décision explicite. Une interdiction robots.txt n'est pas
une garantie de désindexation. Voir :
https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap
https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag

## Observation HTTP bornée, en lecture seule

```sh
python3 tests/ci/publicSeoAudit.py
python3 tests/ci/publicSeoAudit.py --lang fr --lang en
python3 tests/ci/publicSeoAudit.py --namespace-inventory
```

Six index XML, et pour FR/EN seulement : la racine publique et au plus un HEAD
de l'image Open Graph du même hôte. Au maximum un redirect HTTPS même hôte
par requête (20 requêtes HTTP au maximum si chaque requête est redirigée),
réponse GET limitée à 2 Mio, timeout 12 s. L'option --namespace-inventory
ajoute au plus six lectures siteinfo, uniquement avec la requête fixe
namespaces|general (32 requêtes HTTP au maximum avec redirections). Les images doivent avoir une
extension reconnue ; seules les URL sans paramètres ou avec le hash
WikiSEO version=... sont sondées. Les HEAD restent des HEAD après redirect.
Pas de parcours des
sitemaps enfants, de GET des articles listés, de contournement Cloudflare,
de requête à une image externe, de purge ou de paramètre de cache-busting.

Les compteurs par namespace portent sur les **fichiers** de sitemaps, pas
sur le nombre de pages. L'absence de fichier 3100 dans l'index ne prouve pas
que chaque URL est indexable. Les images HEAD non accessibles ne sont pas
nécessairement inaccessibles par GET ou par Googlebot. Les réponses sont des
observations anonymes, non une preuve du comportement de Google Search.
Le workflow public-seo-audit est manuel ou déclenché uniquement par les
changements de cette sonde et de sa documentation ; aucun cron ajouté.
Les tests hors réseau sont bloquants, mais une réponse publique non vérifiée
est rapportée comme telle et ne bloque pas une fusion de code.

Ne pas publier de modification de production sur la seule base de cette sonde.
