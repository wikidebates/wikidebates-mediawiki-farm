# Déploiement des recherches ponctuelles Interlanguage

## Périmètre et décisions

Cette procédure concerne les six éditions FR (centrale), EN, DE, ES, IT, PT,
MediaWiki 1.43.12 et PHP 8.4. Elle s'applique après validation et intégration
de la version hybride (Interlanguage #15). Ne pas déployer une branche
intermédiaire. Les deux architectures expérimentales de normalisation
exhaustive sont abandonnées.

Les tables langlinks et interlanguage_links restent les seules sources de
vérité. Les recherches exactes utilisent leurs index de langue/titre, avec
un ensemble borné de graphies usuelles vérifiées par le parseur local.
Aucune tranche linguistique n'est lue. Les variantes inhabituelles et les
conflits qui échappent à cet ensemble relèvent de l'audit hors rendu.
Une ambiguïté détectée ou une collecte tronquée n'autorise pas une déduction.
Il n'existe plus de nouvelle table, de backfill ou de paramètre de
normalisation. Le déploiement ne touche pas au wikicode, aux correspondances
historiques ni aux caches globaux.

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
un update global MediaWiki : aucune migration du schéma n'est nécessaire.

## 2. Vérifier le schéma existant

Les index (ll_lang,ll_title) et (ill_lang,ill_title), ainsi que les clés
primaires sur identifiant source/langue, suffisent. Leurs noms peuvent
différer de ceux de la CI. Ne pas créer de table ou ajouter de droits SQL.

La table interlanguage_title_keys éventuellement créée pendant l'essai
précédent est ignorée. Ne pas la remplir. Avant toute suppression, vérifier
que les six processus et maintenances utilisent le nouveau code, que les
anciens paramètres restent désactivés ou retirés, que la table est vide ou
sauvegardée hors du webroot et que le retour arrière n'en dépend pas.
La suppression SQL est une opération séparée, explicitement approuvée ;
elle ne doit jamais toucher les tables des correspondances.

## 3. Configuration

Aucune nouvelle option de normalisation. Retirer les deux paramètres
InterlanguageMaintainTitleKeys et InterlanguageUseTitleKeys s'ils ont été
ajoutés lors de l'essai. Conserver les paramètres API, notifications et
purges existants. L'actualisation ciblée secondaire garde son opt-in
actuel tant que les droits réels et le coût des tâches de production ne
sont pas vérifiés. Ne pas introduire de nouveau paramètre.

Vérifier que PHP-FPM et les workers permanents chargent le nouveau code
selon la procédure du serveur. Ne pas vider les files de tâches.

## 4. Plans et temps de recherche

Sur EN puis les autres dépendants, choisir des titres existants, des
correspondances supplémentaires, des titres sans traduction et des cibles
de redirections. Par exemple :

```bash
/usr/bin/php8.4 maintenance/run.php \
	./ops/interlanguage/explainTitleLookup.php --wiki=en \
	--title='Wikidebates:Main Page' --repeat=50
```

Examiner les plans SQL générés réellement (index langue/titre,
rd_ns_title, clés primaires). --strict refuse les balayages centraux
complets ou les recherches incomplètes. Un plan inattendu doit être
étudié sans imposer un FORCE INDEX à l'aveugle.

Comparer les temps médians historiques et bornés sur les mêmes titres
et la même base. Le diagnostic distingue les deux recherches centrales
ponctuelles de la découverte complète (incluant la recherche des alias
locaux et, seulement en cas d'échec natif, la recherche supplémentaire).
Le comparateur historique peut ne pas retrouver une graphie variante :
c'est un comparateur de coût. Les temps CI ne mesurent pas la production.
Ce diagnostic ne purge, ne rend et ne modifie aucune page.

Référence CI (run 45605516081, MariaDB 10.11, 5 000 lignes natives et
5 000 supplémentaires synthétiques, 50 mesures par titre et par branche) :
les six médianes des recherches natives historiques sont comprises entre
0,127 et 0,167 ms, celles des recherches natives bornées entre 0,101 et
0,175 ms et celles des recherches supplémentaires bornées entre 0,136 et
0,205 ms. La découverte inverse complète (hors lecture finale du groupe,
rendu et éventuelle écriture du fallback) est comprise entre 0,448 et
0,778 ms. Aucun balayage central complet. Le cas à variantes historiques
non reconnues compare le coût, pas des résultats identiques. Reprendre
les mesures sur le serveur avant toute décision de production.


L'audit indique bounded_exact_lookup_languages (cinq dépendants après
déploiement), les variantes supportées et les risques
NATIVE_REVERSE_LOOKUP_RISK / SUPPLEMENTAL_REVERSE_LOOKUP_RISK pour les
graphies non découvertes. Examiner ces risques et corriger les liens
natifs dans le wikicode ; ne pas réécrire automatiquement langlinks.
Ne pas confondre une variante syntaxique valide avec une propriété
persistante incorrecte ou une ambiguïté effective.

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
avec le chantier SEO les purges ciblées avec forcelinkupdate et redirects
seulement lorsque leur autorisation a été constatée. Ne jamais élargir les
droits anonymes pour obtenir une recette positive. Les opérations sur les
données éditoriales et les propriétés anciennes relèvent du chantier
d'exploitation séparé.

## Retour arrière

En cas d'échec fonctionnel ou de performance, restaurer les versions et
configurations sauvegardées selon la procédure de release. Il n'existe
plus de commutateur de normalisation. Ne pas supprimer la table
auxiliaire avant la recette. Un retour au code antérieur doit conserver
les anciens lecteurs et écrivains désactivés tant que la table n'existe
pas ou n'est pas alimentée.

En cas de problème de hreflang, restaurer les anciennes routes sauvegardées
sans réintroduire de balises manuelles concurrentes. Une version logicielle
antérieure peut également être restaurée suivant la procédure globale
de release. Ne pas rembobiner main, ni restaurer les tables éditoriales ou
déclencher un nettoyage automatique pour revenir sur la seule normalisation.
