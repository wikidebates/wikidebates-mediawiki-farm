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

## Lot éditorial proposé (61 pages)

Le fichier [pages.json](pages.json) contient pour chaque page le wiki, le titre,
la révision observée (run 45472288074), le type Schema.org et les opérations
exactes de remplacement ou d'ajout. Les 52 appels historiques ont été retrouvés
dans les extraits ; le bloc Évènements est à la jonction des deux extraits
et doit être vérifié dans la source intégrale. Les 9 autres pages sont les
quatre accueils sans modèle historique (FR, DE, IT, PT), quatre portails/aides
FR et Contribute EN. Testez-vous est une redirection, non modifiée. Les
quatre textes d'accueil DE/ES/IT/PT sont actualisés. Les titres fournis sont
complets, title_mode=replace. schema_author est facultatif et réservé aux
articles dont l'auteur est attesté par le texte ou le titre. Pas de date inventée.

Les cinq anciennes présentations traduites sur FR restent des AboutPage
avec métadonnées dans la langue du texte ; ne pas changer leur canonique,
langue effective ou indexabilité sans examen éditorial distinct. Les
comparatifs sont des articles éditoriaux sans auteur déduit ; les essais
signés sont des Article. Le texte espagnol La falsa alternativa de la
globalización est un texte à la première personne signé par son titre,
renvoyant au livre : Article désigne le texte publié, pas le livre.

### Application manuelle sûre

1. Vérifier les prérequis globaux, les révisions actuelles des modèles et
   pages, et sauvegarder les sources intégrales. Tester les modèles sur
   MediaWiki 1.43/PHP 8.4 avant installation.
2. Relire le wikicode intégral : les extraits ne prouvent pas l'absence
   d'un autre appel au milieu de la source. Refuser tout écart de révision,
   toute ambiguïté de bloc ou ajout qui doublerait un appel existant.
3. Installer les modèles puis les appels dans la même fenêtre éditoriale,
   après validation des hreflang et vérifications globales. Pour replace,
   remplacer uniquement before par after, exactement une fois. Pour append,
   ajouter after à la page existante, idéalement avant catégories/liens
   interlangues finaux. Conserver le contenu, catégories et shortdesc.
   Prévisualiser et relire avant enregistrement ; ne pas écraser un changement
   concurrent. Ne pas remplacer une page entière par un bloc SEO.
4. Contrôler les sorties des pages modifiées. La recette globale et les
   caches restent sous la responsabilité de l'autre conversation.

Aucune écriture dans les wikis n'est effectuée par GitHub Actions.
La commande locale décrite ci-dessous est distincte des audits.

Le workflow `editorial-seo-templates.yml` est une recette isolée du parseur
avec MediaWiki 1.43.12, PHP 8.4, le WikiSEO épinglé et 61 appels. Le harnais
SQLite jetable provient de wikidebates-core ; il ne charge aucune configuration
ni base de production. ParserFunctions est chargé seulement en FR pour tester
le modèle ES sans cette dépendance. Les contrôles ne remplacent pas la relecture
des sources intégrales avant édition ni la recette des caches réels.

## Migration locale avec sauvegarde et garde de révision

Après fusion et déploiement du code, utiliser le compte Unix qui exécute
habituellement les maintenances MediaWiki. Le compte MediaWiki choisi doit
déjà exister et avoir les droits d'édition sur chaque édition ; le script
n'en crée pas et ne s'attribue pas d'identité. Ne pas exécuter depuis le web.
Confirmer au préalable les réglages globaux SEO, les vérifications Search
Console et l'activation des hreflang dynamiques pour les six éditions.

Depuis la racine MediaWiki :

```bash
set -e
umask 077
backup="$(mktemp -d "$HOME/editorial-seo-XXXXXX")"
read -r -p 'Compte MediaWiki existant : ' editor
for lang in fr en es de it pt; do
	php maintenance/run.php ./scripts/editorial-seo/applyEditorialSeo.php --wiki "$lang" --user "$editor" --backup-dir "$backup"
done
printf 'Sauvegardes privees : %s\n' "$backup"
```

Le mode sans --apply lit les sources complètes, vérifie les révisions,
les appels (y compris l'inventaire des transclusions), les droits et
l'absence de métadonnées préexistantes pour les ajouts. Il prépare les
64 éditions (61 pages et 3 modèles), sans écriture de page. Chaque JSON
contient le wikicode avant/après. Le répertoire doit être hors de toute
racine web et protégé (0700). Vérifier les différences et les résultats
de la recette GitHub avant d'écrire.

Dans le même shell, et seulement après contrôle :

```bash
for lang in fr en es de it pt; do
	php maintenance/run.php ./scripts/editorial-seo/applyEditorialSeo.php --wiki "$lang" --user "$editor" --backup-dir "$backup" --apply || break
done
```

Le mode --apply revalide tout le lot du wiki, exige une sauvegarde
strictement identique et les six routes dynamiques, puis utilise
PageUpdater::hasEditConflict avant chaque sauvegarde (CAS MediaWiki).
Il écrit les modèles avant leurs appels et enregistre les nouveaux
identifiants de révision dans un journal privé .applied.jsonl. Aucun
effacement global des caches, aucune modification SQL directe et aucun
mot de passe. Ce lot n'est pas une transaction atomique entre wikis.
En cas d'interruption ou de conflit, ne pas relancer aveuglément :
comparer les révisions et le journal avec la sauvegarde. Pour restaurer
une page, vérifier qu'elle est encore à la nouvelle révision enregistrée,
puis annuler cette seule révision via MediaWiki. Les journaux ne sont pas
écrasés. Ne pas utiliser un autre répertoire pour contourner un échec.

Le job source-preflight vérifie les sources publiques intégrales et les
transclusions à l'aide du même transformateur PHP, sans authentification
ni modification du wiki. Toute dérive de révision fait échouer la recette.
