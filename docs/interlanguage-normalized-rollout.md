# Déploiement de la normalisation Interlanguage sans schéma auxiliaire

## Périmètre et décisions

Cette procédure concerne les six éditions FR (centrale), EN, DE, ES, IT, PT,
MediaWiki 1.43.12 et PHP 8.4. Elle s'applique après validation et intégration de la variante sans schéma
auxiliaire (Interlanguage #13). Ne pas déployer une branche intermédiaire.
La normalisation est désormais intégrée au fonctionnement ordinaire de
l'extension, sans paramètre d'activation, table auxiliaire ni backfill.

Les tables langlinks et interlanguage_links restent les seules sources de
vérité. Une lecture indexée de la tranche linguistique fournit les
graphies réelles; le parseur MediaWiki du wiki destinataire détermine leur
identité. Le cache natif ne mémorise qu'une transformation de titres simples,
jamais les correspondances ou les identifiants sources. Les alias et
préfixes interwiki sont interprétés en direct. L'absence de cache ne change
pas le résultat. Les mises à jour SQL directes n'exigent plus de backfill.

La contrepartie est une lecture de tranche d'index par table consultée lors
d'un véritable parse, au lieu d'une recherche ponctuelle sur un index
normalisé persistant. Les limites de découverte évitent les déductions
incomplètes. Vérifier les plans et le coût sur la ferme avant de retenir
cette version.

La migration ne touche pas au wikicode, aux liens natifs, aux correspondances
historiques ni aux caches globaux. Une ambiguïté ou une recherche
incomplète n'autorise pas un nouveau lien.

Les redirections distantes restent une question séparée. WikiSEO sait
normaliser les titres et préfixes connus mais ne doit pas inventer leur
destination finale. Un dictionnaire statique de redirections, même issu
d'un audit, peut devenir périmé dès une édition, un déplacement ou une
suppression. Pour le faible nombre de redirections relevé, préférer
l'audit des destinations vérifiées, puis la correction éditoriale des
liens natifs et la réconciliation ciblée des relations automatiques.
N'ajouter ni consultation HTTP pendant le rendu ni table de redirections
répliquée sans besoin démontré. Les observations HREFLANG_EFFECTIVE_REDIRECT
et HREFLANG_STORED_REDIRECT distinguent une correspondance sémantiquement
valide d'une URL qui peut désigner un alias non canonique. L'audit ne prouve
pas que cette URL est réellement publiée dans le HTML ni indexable.

## 1. Préparation (lecture seule)

Exécuter sous le compte habituel de déploiement, sans toucher aux fichiers
locaux non suivis, depuis la racine MediaWiki. Vérifier les versions, les
pins et les modifications éventuelles avant de déployer.

```bash
cd /home/users/webmaster/www/farm.wikidebates.org/w
/usr/bin/php8.4 --version
git status --short --branch
git submodule status -- extensions/Interlanguage extensions/WikiSEO
git fetch --no-recurse-submodules wikidebates main
git log -1 --oneline wikidebates/main
git diff --stat HEAD wikidebates/main
git ls-tree wikidebates/main -- extensions/Interlanguage extensions/WikiSEO
```

Choisir un SHA approuvé. Sauvegarder le SHA de la ferme, ses pins et les
fichiers de configuration réellement chargés dans un répertoire privé,
hors du webroot. Vérifier les sauvegardes avant d'intervenir. Suspendre les
imports et modifications SQL directes des correspondances durant la
migration. Conserver les workers et timers existants ; vérifier leur état,
ne pas vider les files de tâches. Un compte SQL FR ne doit pas avoir besoin
d'accéder aux bases des autres éditions.

Déployer avec la procédure ordinaire de fast-forward et de mise à jour des
sous-modules à leurs commits épinglés (pas de --remote). Ne pas lancer
un update global MediaWiki uniquement pour cette nouvelle table.

## 2. Vérifier le schéma existant (aucune migration)

Ne pas créer de table, exécuter update.php ou changer les droits SQL.
Les recherches utilisent (ll_lang,ll_title) et (ill_lang,ill_title),
ainsi que les clés primaires sur identifiant source/langue. Les noms des
index peuvent différer de ceux de la CI. Vérifier ces index sur FR.

La table interlanguage_title_keys, si elle a été créée lors d'un essai
antérieur, n'est plus utilisée et n'est pas supprimée automatiquement.
Ne pas la remplir. La laisser en place jusqu'à validation et décision
explicite de suppression. Ne pas modifier les tables éditoriales.

## 3. Configuration et processus

Aucun nouveau paramètre n'est nécessaire à la normalisation.
Les anciennes options InterlanguageMaintainTitleKeys et
InterlanguageUseTitleKeys peuvent être retirées si elles avaient été
ajoutées. Conserver tous les réglages existants des API, des notifications,
du cache et des namespaces. Le troisième paramètre ajouté lors de l'essai,
InterlanguagePurgeLinkUpdateLanguages, devient également inutile : les jobs
asynchrones demandent automatiquement une mise à jour secondaire ciblée
aux éditions dépendantes. FR conserve les purges ordinaires.

Vérifier que PHP-FPM et les workers CLI utilisent le nouveau code
(redémarrage/rechargement selon la procédure du serveur).

## 4. Mesurer avant d'étendre la recette

Sur un wiki dépendant, exécuter sur un titre connu :

```bash
/usr/bin/php8.4 maintenance/run.php \
	./ops/interlanguage/explainTitleLookup.php --wiki=en \
	--title='Wikidebates:Main Page' --repeat=20
```

Examiner le plan réel (index de langue/titre, index de redirections et
clés primaires), les temps SQL, les temps complets de recherche et le
backend de cache effectivement sélectionné. La comparaison avec la
recherche exacte historique est une référence de coût, pas d'équivalence
des résultats. --strict refuse un balayage central complet ou une
découverte incomplète. Les temps de CI ne constituent pas une mesure de
la production. Le script ne purge, ne rend et ne modifie aucune page
(les entrées de mémoisation du cache natif peuvent être créées).

Vérifier normalized_lookup_languages et les alertes NORMALIZED_LOOKUP_*
du rapport. Les cinq wikis dépendants doivent utiliser la version
normalisée. Aucun compteur de couverture d'une table auxiliaire n'est
nécessaire.

## 5. Exporter les règles hreflang réelles

L'export ne lit que les paramètres locaux de chaque wiki, ne modifie
aucune configuration et ne consulte pas les autres bases. Avec umask 077,
générer les six fichiers hors du webroot, dans un répertoire neuf :

```bash
(
	set -euo pipefail
	umask 077
	dir=$(mktemp -d /home/users/webmaster/files/interlanguage-audits/title-rules.XXXXXXXX)
	for wiki in fr en de es it pt; do
		/usr/bin/php8.4 maintenance/run.php \
			./ops/interlanguage/exportHreflangTitleRules.php --wiki="$wiki" \
			>"$dir/$wiki.json"
	done
	python3 - "$dir" <<'PY'
import json
from pathlib import Path
import sys

folder = Path(sys.argv[1])
rules = {}
for lang in ("fr", "en", "de", "es", "it", "pt"):
	data = json.loads((folder / (lang + ".json")).read_text(encoding="utf-8"))
	if data["language"] != lang:
		raise SystemExit("Unexpected edition: " + lang)
	rules[lang] = data["titleRules"]
with (folder / "title-rules.json").open("x", encoding="utf-8") as output:
	json.dump(rules, output, ensure_ascii=False, indent="\t")
	output.write("\n")
PY
	printf 'Règles à examiner : %s/title-rules.json\n' "$dir"
)
```

Comparer les six maps avec les alias attendus. Incorporer les trois clés
namespaceAliases, namespaceCase et ucfirstOverrides à chaque route
existante de wgWikiSeoInterlanguageArticleUrls, en préservant article,
mainPageTitle, mainPageUrl et les URL canoniques actuelles. Générer un
tableau PHP statique au moment de la configuration ; ne pas lire le JSON
ou calculer les règles à chaque requête HTTP. Les six éditions doivent
recevoir les mêmes règles distantes. Exporter à nouveau après modification
des namespaces ou des exceptions de capitalisation. Cet export ne résout
pas les redirections ni l'indexabilité des cibles.

## 6. Recette finale ciblée et arrêt

```bash
bash tests/ci/runInterlanguageMappingAudit.sh
python3 tests/ci/interlanguageMappingAudit.py --public
```

Conserver les rapports privés, les plans et les SHA. Comparer avec
l'inventaire précédent : conflits, redirections, cibles manquantes,
propriétés absentes/malformées et anciens pairs. Les valeurs zéro ne sont
pas des objectifs artificiels. L'observation publique est bornée et
ne purge aucune page. Le rapport ne remplace pas les tests de rendu,
l'examen des canonicals ou des caches personnalisés.

Ne pas lancer de reconstruction globale de page_props, des caches Vector
ou Minerva, ni de purge générale dans cette procédure. Vérifier en lien
avec le chantier SEO que les purges automatiques ciblées obtiennent bien
les deux acquittements purge et linkupdate sur les éditions dépendantes.
MediaWiki vérifie lui-même les droits, indépendamment. Si linkpurge est
refusé, la purge ordinaire peut réussir et le job signale l'absence de
mise à jour, sans contourner la restriction ni réessayer automatiquement.
Ne jamais élargir les droits anonymes pour obtenir une recette positive. Les opérations sur les
données éditoriales et les propriétés anciennes relèvent du chantier
d'exploitation séparé.

## Retour arrière

En cas de problème de performance ou de fonctionnalité, restaurer les
versions logicielles et configurations sauvegardées selon la procédure de
release. Il n'existe plus de commutateur de normalisation à modifier.
Les entrées dérivées du cache natif expirent naturellement ; elles ne
sont pas des correspondances. Ne pas supprimer de table pour revenir
sur la normalisation. Une ancienne table auxiliaire restée inutilisée
peut être conservée pendant la recette.

En cas de problème de hreflang, restaurer les anciennes routes sauvegardées
sans réintroduire de balises manuelles concurrentes. Une version logicielle
antérieure peut également être restaurée suivant la procédure globale
de release. Ne pas rembobiner main, ni restaurer les tables éditoriales ou
déclencher un nettoyage automatique pour revenir sur la seule normalisation.
