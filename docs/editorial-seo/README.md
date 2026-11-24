# SEO éditorial — candidats de migration des modèles historiques

**Candidats non déployés.** Ne pas copier les modèles dans les wikis avant
la recette des appels, la confirmation de la configuration globale et la
validation du rendu sur MediaWiki 1.43.12 / PHP 8.4.

Inventaire du [run 45471797097](https://github.com/wikidebates/wikidebates-mediawiki-farm/actions/runs/45471797097)
(PR #190) : 29 transclusions de Métabalises (FR, révision 118860), 16 de
Meta tags (EN, révision 1400), 7 de Metaetiquetas (ES, révision 257).
Les trois listes sont complètes sans continuation. Les pages observées
sont répertoriées explicitement dans l'inventaire éditorial existant pour
examiner leurs extraits et propriétés sans suivre de lien. Deux anciennes
traductions sur le wiki FR sont dans l'espace principal ; ne pas confondre
avec les pages natives de débat/argument. La liste ne prouve pas à elle
seule la nature ou les paramètres des appels.

## Candidats complets

| Wiki | Page | Fichier |
| --- | --- | --- |
| fr | Modèle:Métabalises | [fr.wikitext](templates/fr.wikitext) |
| en | Template:Meta tags | [en.wikitext](templates/en.wikitext) |
| es | Plantilla:Metaetiquetas | [es.wikitext](templates/es.wikitext) |

Un seul appel #seo par modèle. Les anciens paramètres de titre, description,
mots-clés et type restent acceptés ; les deux listes FR sont combinées
sans séparateur orphelin (ParserFunctions existant sur FR). Le modèle
espagnol ne dépend plus de #if : le #if du modèle historique apparaît
littéralement dans les propriétés, sans que la raison de sa non-expansion
soit établie. La valeur vide est ignorée par TagParser et setMetadata
(WikiSEO e73f65035731). Une description explicite prime sur shortdesc.
Ne pas supprimer une description spécifique pour une shortdesc générique.
Ne pas ajouter de description générique commune.

Le titre fourni devient le titre complet sans suffixe ajouté par le modèle ;
si absent, laisser MediaWiki et WikiSEO produire leur titre. Vérifier
les anciens titres FR dépourvus de nom du site et ajouter un suffixe
court à l'appel lorsque pertinent. Le titre peut servir aussi à Open Graph,
X et Schema.org. L'ancien type (type EN, tipo ES) reste accepté ; une valeur
article explicite donne Article et og:type=article sauf types explicites
différents. Par défaut website et WebPage. Ajouter type=article (ou
tipo=article) uniquement après lecture d'un véritable article.
section est facultatif, sans rubrique générique automatique.
Les images sociales existantes restent les valeurs par défaut. Si image
est personnalisée, fournir aussi image_alt (ou image_alt= pour l'omettre) ;
sans cela le texte historique du visuel standard serait inapproprié.
Ne pas inventer des dimensions pour les images personnalisées.

## Prérequis de déploiement (coordination)

- Activer et tester WikiSeoInterlanguageArticleUrls et Interlanguage sur
  les six éditions avant de supprimer les hreflang manuels.
- Confirmer la vérification Google actuelle pour chaque propriété Search
  Console et sa prise en charge via wgGoogleSiteVerificationKey.
  Le paramètre historique google-site-verification ne figure pas dans le
  Validator actuel ; ne pas supposer qu'il garantit une vérification.
  Vérifier aussi les éventuelles vérifications DNS ou fichier existantes.
- Le compte X est conservé dans les modèles à titre de repli ; la configuration
  globale wgTwitterSiteHandle a priorité. site_name et locale sont conservés
  par langue ; les DE/IT/PT utilisent encore leur appel #seo direct.
- Ne pas créer SEO projet / SEO project en parallèle des modèles existants.
- Vérifier la génération SchemaOrg, WebSite et Organization avec la
  conversation responsable de la configuration globale.
- Sauvegarder les sources et revid de chaque page ; ne pas écraser une
  révision concurrente. Revoir tous les appels observés, y compris les
  transclusions indirectes et les nouveaux appels apparus depuis le relevé.
- Tester les valeurs vides, les valeurs explicites, les mots-clés FR seuls
  ou combinés, les types legacy et nouveaux, les images personnalisées,
  le modèle ES sans #if et la priorité shortdesc. Vérifier le HTML et
  JSON-LD de pages représentatives sans purger les caches globalement.
- Effectuer les éditions de pages et modèles après accord avec l'autre
  conversation, puis sa recette finale avec les caches actualisés.

Aucun PHP, Lua, SQL, sous-module ou contenu de production modifié par
cette proposition.
