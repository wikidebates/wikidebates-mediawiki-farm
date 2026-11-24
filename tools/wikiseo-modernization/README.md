# WikiSEO modernisation — travail preparatoire Wikidebates

Ce repertoire contient une **surcouche de fichiers PHP**, non active sur le site,
pour moderniser WikiSEO 2.7.0 sans modifier le sous-module externe de la ferme.
Il ne faut **pas** charger ces fichiers en parallele de WikiSEO : ils sont destines
a etre copies dans un **depot GitHub WikiSEO dedie**, apres creation de ce depot.

## Reference et garde-fous

- Cible : MediaWiki **1.43** et PHP **8.4**.
- Source : `wikimedia/mediawiki-extensions-WikiSEO`, commit
  `3b135415f9fe0eff4d23659b4143bd50009238ab`.
- `apply.sh` accepte aussi l'amont `REL1_43` au commit
  `0496485749063be68fbe3a3aa1bd444979ec262a`.
- Les modifications sont proposees dans `tools/wikiseo-modernization/overrides/`.
- Aucun changement de sous-module, aucun changement de production, aucune
  exclusion automatique de WikiSEO sur les pages Wikidebates.
- Les balises existantes `#seo` et `<seo>` restent utilisables.
- **Logo conserve** : les valeurs existantes de `WikiSeoDefaultImage` et de
  `WikiSeoDisableLogoFallbackImage` restent prises en compte.
- Les dates de publication ne sont jamais deduites de la derniere modification.
- Les balises `article:*` ne sont publiees que pour un `og:type=article`.
- Le type Schema.org est distinct du type Open Graph : `schema_type=WebPage`
  et `og_type=website` (les vieux `type=article` restent compris).
- Le projet s'appelle **Wikidebates** ; cela concerne aussi l'extension Wikidebates
  qui produit directement les metadonnees de debats et arguments.

## Obtenir le code et tester sans ecriture

Une fois un depot dedie a WikiSEO cree par un administrateur, a partir du
commit amont de reference, utiliser une branche de fonctionnalite propre :

```sh
	git -C /chemin/WikiSEO switch -c feature/wikiseo-modernization
	sh tools/wikiseo-modernization/apply.sh --check /chemin/WikiSEO
	sh tools/wikiseo-modernization/apply.sh --apply /chemin/WikiSEO
	git -C /chemin/WikiSEO diff --check
	find /chemin/WikiSEO/includes -name '*.php' -exec php -l {} \;
	php tools/wikiseo-modernization/tests/meta_values.php
```

La commande `--check` est un audit a blanc. `--apply` modifie uniquement
le checkout cible apres verification stricte de son SHA et de sa propre branche
feature ; elle refuse un depot sale et les branches principales.

## Parametres nouveaux (optionnels)

| Parametre | Utilite |
| --- | --- |
| `og_type` | Type Open Graph : `website` par defaut, `article` explicitement |
| `schema_type` | Type Schema.org : `WebPage` par defaut, `AboutPage`, `Article`, `QAPage`, etc. |
| `schema_author` | Auteur explicite d'un article ; jamais invente |
| `image_type`, `image_secure_url` | Type MIME et URL HTTPS d'une image |
| `locale_alternate` | Locales Open Graph alternatives, separees par virgules |
| `twitter_title`, `twitter_description`, `twitter_image`, `twitter_image_alt` | Carte X personnalisee |
| `twitter_creator` | Compte X d'un auteur explicite |

L'utilisation de `type=article` reste compatible : elle signifie
`og:type=article` et `@type=Article`. `type=website` est mappe vers
`og:type=website` et `@type=WebPage`. Un `schema_type` explicite peut
deroger au type Schema.org correspondant.

## Modele facultatif pour les pages de projet

A creer manuellement sur le wiki apres deploiement d'une version validee
de WikiSEO. Exemple **de contenu de `Modele:SEO projet`** :

```wikitext
{{#seo:
	|title={{{titre|{{PAGENAME}}}}}
	|description={{{description|}}}
	|image={{{image|}}}
	|og_type=website
	|schema_type=WebPage
}}
```

Utilisation sur une page qui en a besoin :

```wikitext
{{SEO projet
	|description=Presentation du projet Wikidebates et de son fonctionnement.
}}
```

Cette inclusion n'est **jamais automatique**. En l'absence d'image renseignee,
WikiSEO conserve son repli de configuration vers le logo.

## Validation requise avant adoption

- Tests MediaWiki 1.43 reels sur une installation de test en francais et anglais.
- HTML des pages ordinaires, projets, debats et arguments Wikidebates,
  historiques, diff, Special, apercu de modification, mobile et anon.
- Tests de priorite et de suppression des `page_props` sur editions repetitives,
  modifications de modeles, invalidation du ParserCache et des caches fichier.
- Entetes Open Graph, X/Twitter et JSON-LD ; verification des images par les
  robots avec Cloudflare et les protections anti-crawlers.
- Mesures de cout processeur / SQL avant et apres, puis revue de securite.

Le repertoire est un **premier lot code**, pas encore une extension deployee ;
ne pas fusionner une PR contenant seulement ces fichiers en s'attendant a
ce qu'ils changent les metadonnees de la production.
