# Activation progressive de la normalisation Interlanguage

## Périmètre et décisions

Cette procédure concerne les six éditions FR (centrale), EN, DE, ES, IT, PT,
MediaWiki 1.43.12 et PHP 8.4. Elle s'applique après validation des PR
Interlanguage #10 et #11, WikiSEO #17 et ferme #199 à #203, et choix du
commit exact du déploiement. Ne pas redéployer une branche intermédiaire.
La version du code ne constitue pas une preuve de l'activation du nouvel
index ou de la mise à jour des propriétés persistantes.

La migration ne touche pas au wikicode, aux liens natifs, aux correspondances
historiques ni aux caches globaux. La table auxiliaire contient uniquement
des clés de découverte de titres bruts. Le wiki destinataire décide de
l'identité exacte d'un candidat à l'aide du parseur de titres MediaWiki.
Une ambiguïté ou une recherche incomplète n'autorise pas un nouveau lien.

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

## 2. Schéma auxiliaire central

À ce stade, après le chargement des extensions, les paramètres
InterlanguageMaintainTitleKeys et InterlanguageUseTitleKeys doivent rester
désactivés. Sur FR seulement, vérifier que la table n'existe pas déjà,
et vérifier les index existants sur langlinks et interlanguage_links.

```bash
printf '%s\n' '$db = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase(); echo $db->getDomainID(), "\n"; foreach (["langlinks","interlanguage_links","interlanguage_title_keys"] as $table) { if ($db->tableExists($table,__FILE__)) { foreach ($db->query("SHOW INDEX FROM ".$db->tableName($table),__FILE__) as $row) { echo $table," ",$row->Key_name," ",$row->Seq_in_index," ",$row->Column_name,"\n"; } } else { echo $table,": absent\n"; } }' |
	/usr/bin/php8.4 maintenance/run.php eval --wiki=fr
```

Vérifier que le domaine est bien celui de FR. Les recherches de liens
nécessitent les index sur (ll_lang,ll_title) et (ill_lang,ill_title), en plus
des clés primaires sur identifiant source/langue. Si un index est absent,
faire examiner le schéma par l'administrateur SQL ; ne pas lancer un ALTER
TABLE à l'aveugle.

Si et seulement si la table auxiliaire est absente, créer exclusivement
cette table sur FR avec la maintenance SQL de MediaWiki qui interprète les
variables de schéma (préfixe et options de table) :

```bash
/usr/bin/php8.4 maintenance/run.php sql --wiki=fr \
	extensions/Interlanguage/sql/interlanguage_title_keys.sql
```

Vérifier les colonnes, InnoDB et les index de la table créée. Si elle existe
déjà, contrôler sa conformité au fichier SQL ; ne pas la supprimer ni
la recréer. Le DBA doit vérifier les privilèges des comptes effectifs
FR et des cinq dépendants sur cette table centrale : SELECT et INSERT,
sans nouveau droit UPDATE ou DELETE, sans élargissement des droits sur
les autres bases. Conserver le résultat de cette vérification en privé.
La création de la table peut nécessiter un compte d'administration SQL.

## 3. Activer les écrivains, pas encore les lecteurs

Après le chargeur Interlanguage, configurer sur les six wikis :

```php
$wgInterlanguageMaintainTitleKeys = true;
```

Sur les cinq dépendants, conserver pour l'instant :

```php
$wgInterlanguageUseTitleKeys = false;
```

Vérifier que les processus PHP-FPM et les workers CLI utilisent cette
configuration (redémarrage/rechargement selon la procédure du serveur).
Ne pas modifier les réglages existants des API, des notifications ou de
purge pour réaliser cette migration. Ne pas activer un lecteur avant que
tous les écrivains maintiennent les nouvelles clés.

Puis, sur FR seulement :

```bash
/usr/bin/php8.4 maintenance/run.php \
	./extensions/Interlanguage/maintenance/buildTitleKeys.php --wiki=fr --apply

/usr/bin/php8.4 maintenance/run.php \
	./extensions/Interlanguage/maintenance/buildTitleKeys.php --wiki=fr
```

La première commande ajoute uniquement les clés auxiliaires manquantes ;
la seconde est une vérification en lecture seule. Exiger Missing auxiliary
keys: 0. Examiner séparément Unindexable spellings : des titres invalides
peuvent exister et ne doivent pas être artificiellement normalisés.
L'audit utilise les tables réelles et le parseur de chaque édition, sans
requête HTTP publique. Relancer l'audit privé avant d'activer les lecteurs.

## 4. Activer et vérifier les lecteurs progressivement

Commencer par EN, puis DE, ES, IT, PT une fois les contrôles réussis.
Sur chaque wiki dépendant, après le chargeur :

```php
$wgInterlanguageUseTitleKeys = true;
```

Après EN, exécuter sur des titres locaux existants et traduits connus,
par exemple :

```bash
/usr/bin/php8.4 maintenance/run.php \
	./ops/interlanguage/explainTitleLookup.php --wiki=en \
	--title='Wikidebates:Main Page'
```

Vérifier le plan : itk_lookup, ll_lang, ill_lang_title, rd_ns_title et
les clés primaires doivent être disponibles. --strict refuse un balayage
central complet ou une découverte incomplète ; le choix de plan dépend
des statistiques et de la cardinalité réelles. Le test CI sur MariaDB
10.11 et 10 000 lignes supplémentaires n'est pas une mesure de la
production. Un plan inattendu doit être étudié avant de poursuivre, sans
imposer aveuglément un FORCE INDEX. La commande ne lance ni purge ni rendu.

Contrôler le rapport après chaque étape (les autres lecteurs peuvent
encore être désactivés). Vérifier les compteurs title_key_active_writers,
title_key_active_readers, title_key_missing_spellings et les alertes
NORMALIZED_INDEX_*. À la fin, attendre six écrivains et cinq lecteurs ;
ne pas confondre les écarts de propriétés historiques ou les titres
virtuels avec des échecs du nouvel index.

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
/usr/bin/php8.4 maintenance/run.php \
	./extensions/Interlanguage/maintenance/buildTitleKeys.php --wiki=fr
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

En cas d'échec, désactiver immédiatement les lecteurs normalisés des
wikis concernés (InterlanguageUseTitleKeys = false), conserver les
écrivains uniquement si la table et leurs droits sont corrects, et
recharger la configuration des processus selon la procédure habituelle.
Si la table ou les droits sont en cause, désactiver tous les lecteurs
avant les écrivains. La lecture exacte historique reste disponible.
Les clés auxiliaires devenues inutiles ne sont pas des correspondances et
peuvent rester en place ; aucune suppression SQL n'est nécessaire.
Avant toute reprise, réactiver les écrivains, refaire le backfill et
vérifier la couverture.

En cas de problème de hreflang, restaurer les anciennes routes sauvegardées
sans réintroduire de balises manuelles concurrentes. Une version logicielle
antérieure peut également être restaurée suivant la procédure globale
de release. Ne pas rembobiner main, ni restaurer les tables éditoriales ou
déclencher un nettoyage automatique pour revenir sur la seule normalisation.
