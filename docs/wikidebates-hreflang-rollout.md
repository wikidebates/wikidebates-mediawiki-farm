# Hreflang deployment handoff

WikiSEO's new optional interwiki mode uses already populated OutputPage
language links from Interlanguage, not the UI resolver. It requires no
additional SQL, remote call or cache. See the WikiSEO repository's
docs/interlanguage-hreflang.md for configuration and limitations.

The farm pins WikiSEO and retains the Interlanguage runtime code unchanged.
The extended integration checks run with the six language codes, actual
central and dependent MediaWiki/MariaDB instances, Vector, MobileFrontend,
and a pinned public Minerva REL1_43 checkout. The private Minerva fork is
not accessible using the default public farm Actions token.

Before the grouped production deployment, review the actual canonical
article paths on all six editions, including whether each main page is
canonical at its root. The recommended shared settings are:

```php
$wgEnableCanonicalServerLink = true;
$wgWikiSeoDefaultLanguage = null;
$wgWikiSeoInterlanguageArticleUrls = [
	'fr' => 'https://fr.wikidebates.org/wiki/$1',
	'en' => 'https://en.wikidebates.org/wiki/$1',
	'de' => 'https://de.wikidebates.org/wiki/$1',
	'es' => 'https://es.wikidebates.org/wiki/$1',
	'it' => 'https://it.wikidebates.org/wiki/$1',
	'pt' => 'https://pt.wikidebates.org/wiki/$1',
];
```

Do not add a nonindexable or inactive edition to the map. If a canonical
main page uses the domain root, use WikiSEO's optional main-page route
configuration. Keep the existing LanguageCode on each wiki. Prefer deriving
the map from the farm's shared canonical URL configuration.

The HTML output will change when a page is freshly rendered. This PR does
not purge or rebuild existing parser, HTML, fragment, mobile or CDN caches.
Coordinate their handling with the grouped SEO rollout. Roll back the new
head behavior by setting the route map to an empty array; previously cached
HTML can retain its previous annotations.

For release validation, compare actual canonical and alternate URLs on
reciprocal FR/EN and other language pages, including institutional pages,
debates and arguments. Verify old revisions, noindex, and no translation.
The local renderer cannot know whether a remote mapping is stale, redirects
to a different canonical title or targets a noindex page without extra I/O.
This check is an explicit deployment audit, not request-time verification.
