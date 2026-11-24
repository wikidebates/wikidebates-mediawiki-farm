# Déploiement et contrôles SEO hors débats et arguments

## Configuration relevée le 24 novembre 2026 (FR et EN)

Le serveur et le serveur canonique sont déjà en HTTPS sur le bon sous-domaine.
EnableCanonicalServerLink est activé. MetadataGenerators contient OpenGraph et
Twitter, mais pas SchemaOrg. ShortDescriptionEnableTagline est actif ;
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
```

Six index XML, et pour FR/EN seulement : la racine publique et au plus un HEAD
de l'image Open Graph du même hôte. Au maximum un redirect HTTPS même hôte
par requête, réponse GET limitée à 2 Mio, timeout 12 s. Pas de parcours des
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
