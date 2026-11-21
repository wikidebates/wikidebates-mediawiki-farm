# Pilote SMW : préparation de la préproduction Wikidébats

> **État : préparation GitHub uniquement. Aucune préproduction n'a été
> créée ou identifiée ici ; aucun changement sur les serveurs publics.**
> Cette procédure ne doit JAMAIS être exécutée dans
> `/home/users/webmaster/www/farm.wikidebates.org/w`.
>
> La PR de ferme n°20 reste en **brouillon**. Le sous-module
> `extensions/Wikidebates` est épinglé au commit testé du dépôt core
> `01801327ea53304767ce52350f1b43eb799fb17f` (PR n°52 incluse). Le repli conservateur expérimental
> de la PR core n°50 n'est **pas** compris dans ce commit.

## 1. Barrières obligatoires avant toute commande de modification

1. **Identifier** un répertoire distinct pour le clone de préproduction,
	avec un serveur HTTP, des VirtualHosts et des domaines de test dédiés.
	Ne pas toucher au répertoire ou à l'installation du site public.
2. Cloner uniquement des bases de données **isolées** pour FR et EN :
	les comptes SQL de préproduction ne doivent avoir aucun droit
	d'écriture sur les bases publiques. Vérifier `DBname`, `DBserver`,
	`DBuser`, `DBprefix`, `DBTableOptions` et la connexion SMW réelle.
	Ne jamais relier la préproduction au magasin primaire de production.
3. Isoler les `job` queues, sessions, `MainCacheType`,
	`ParserCacheType`, cache de fragments, fichiers HTML,
	MinervaFileCache, `CacheDirectory`, `FileCacheDirectory`,
	`UploadDirectory` et le stockage des images.
	Éviter toute propagation des hooks de purge vers Cloudflare public.
4. Désactiver en préproduction l'envoi de courriels, les webhooks,
	les tâches planifiées externes, le CDN HTML public et les workers
	de production. Restreindre l'accès et empêcher l'indexation.
5. Noter les chemins **staging uniquement**, les wikis copiés,
	les identifiants Git exacts, la date de la copie et le moyen de
	revenir à l'instantané original. Faire approuver l'isolation
	avant toute commande `update`, `refreshLinks` ou édition.

**Arrêt impératif** si une cible SQL, un volume, une file de jobs ou
un domaine public est partagé avec la production. Une copie de
`LocalSettings.php` ne constitue pas à elle seule une isolation.

## 2. Installer et identifier le code, uniquement dans le clone

Depuis la **racine du clone indépendant**, dont le chemin aura été
validé explicitement (ne jamais remplacer cela par le chemin public) :

```sh
git submodule update --init extensions/Wikidebates
git -C extensions/Wikidebates rev-parse HEAD
git -C extensions/Wikidebates status --short
```

La sortie du premier `rev-parse` doit être exactement
`01801327ea53304767ce52350f1b43eb799fb17f`. Si ce n'est pas le cas, arrêter.

Une fois les connexions/volumes de staging indépendants prouvés,
effectuer l'étape MediaWiki standard de migration de schéma,
**sur chaque copie seulement** :

```sh
php maintenance/run.php update --wiki=fr
php maintenance/run.php update --wiki=en
```

Il est normal que des jobs ordinaires soient déclenchés pendant des
migrations : ils doivent rester dans la file isolée de préproduction.

## 3. Contrôler le prérequis SMW avant activation

Sur les bases de préproduction, avec les valeurs
`WikidebatesSemanticCacheTracking=false` et
`WikidebatesSemanticCacheEagerPurge=false` au départ :

```sh
php maintenance/run.php Wikidebates:CheckSemanticCache --wiki=fr --json
php maintenance/run.php Wikidebates:CheckSemanticCache --wiki=en --json
php maintenance/run.php Wikidebates:AuditSemanticFreshness --wiki=fr --namespaces=0 --limit=200 --batch-size=50 --summary-only
php maintenance/run.php Wikidebates:AuditSemanticFreshness --wiki=en --namespaces=0 --limit=200 --batch-size=50 --summary-only
```

La version corrigée de l'audit (PR core n°52) ajoute les champs
`redirects_skipped`, `query_ids`, `dependency_rows`,
`query_ids_without_dependency_rows` et
`pages_with_incomplete_dependencies`.
**Le champ historique `query_links` compte des références à des
requêtes, et non les lignes SQL de `smw_query_links`.**

Observer en particulier trois pages copiées, en les retrouvant
par **titre** si les `page_id` changent :

- FR « Dieu est une invention » (référence : 3016 ; cinq profils
	`_ASK` observés en production, garde-fou certifiable).
- FR « Il est possible de réduire le taux de chômage dans les pays
	industrialisés » (référence : 1220 ; zéro profil en production).
- FR « Les promoteurs privés ne doivent pas pouvoir décider de
	l'avenir des Murs à pêches de Montreuil » (référence : 1319 ;
	zéro profil en production).

Les deux derniers cas ont bien `{{Argument}}` dans leur source ;
ils doivent **échouer prudemment** au contrôle de lecture avant
reconstruction. Les profils non nuls sur d'autres arguments ne
prouvent pas que toutes leurs requêtes PHP natives sont tracées.

## 4. Activer le pilote sur la copie isolée seulement

Après validation des barrières, dans **la configuration de staging
uniquement**, sans modifier le `LocalSettings.php` public :

```php
$wgJobTypesExcludedFromDefaultQueue[] = 'wikidebatesSemanticCachePurge';
$wgWikidebatesSemanticCacheTracking = true;
$wgWikidebatesSemanticCacheEagerPurge = false;
```

Le résultat `purge_preconditions_met=false` est attendu car
`EagerPurge=false`, et **n'autorise pas** l'exécution manuelle de
`wikidebatesSemanticCachePurge`. Les fichiers HTML complets des
arguments dépendant des requêtes sémantiques doivent être refusés
par les hooks, y compris via MinervaFileCache.

Une consultation anonyme ou un appel `ApiParse` peut créer un
`ParserOutput` annoté sans enregistrer les profils SQL durables
`_ASK`. Ne pas interpréter une réponse HTTP 200 comme un succès
de la migration.

## 5. Rafraîchissement ciblé, vérifié et limité à staging

Faire d'abord une sauvegarde horodatée du contenu et des données
secondaires de la copie. Sur les deux arguments historiques de
**staging uniquement**, vérifier le titre, l'identifiant, la dernière
révision et l'absence initiale de profils `_ASK`.

Exécuter un **seul job MediaWiki standard `RefreshLinksJob` par
page copiée**, en reproduisant l'intégration validée dans
`tests/fullstackSemanticCache.php` :

```php
$title = \MediaWiki\Title\Title::newFromText('Titre d’argument de test');
$job = new \RefreshLinksJob($title, [
	'causeAction' => 'WikidebatesSemanticStagingPilot',
	'causeAgent' => 'Wikidebates isolated staging',
]);
$result = $job->run();
```

Ce fragment PHP est un **exemple à exécuter depuis le bootstrap
MediaWiki de staging**, après contrôle de l'isolation et du titre ;
ce n'est **pas** une commande à coller dans `eval.php` sur la ferme
publique. Ne pas lancer `rebuildData.php` ni `runJobs` en masse.

Vérifier après chaque job : identifiant de dernière révision
inchangé, profils `_ASK` présents, **lignes SQL réelles de
dépendance** présentes pour chaque requête et
`guard_safe_to_read=true`. Si l'un de ces contrôles échoue,
arrêter le pilote sans activation publique.

## 6. Essai de fraîcheur HTTP avec relation de test

Créer des **pages jetables dans le wiki de préproduction**, avec
une relation de parent visible dans le HTML complet. Conserver
une valeur de départ et vérifier le HTML des surfaces suivantes :

1. Vue complète Vector, servie par HTTP.
2. Vue complète Minerva, vérifier
	`X-Minerva-File-Cache: BYPASS; reason=extension-veto` pour
	la page argument sémantique.
3. API `action=wikidebatesreading` avec skins Vector et Minerva.
4. Une page Débat normale, dont le HTML ne dépend pas d'une
	recherche SMW, doit conserver de vrais HIT du cache Minerva.

**Modifier une relation sur une autre page** après avoir amorcé
les caches, laisser les jobs de purge sémantique en attente et
comparer avec les valeurs SMW lues directement. Les changements
doivent être visibles au prochain HTTP même sans purge anticipée.
Tester ajout, suppression, parent multiple, renommage et passage
d'une requête vide à un résultat.

La sonde existante `maintenance/probeSemanticHttp.cjs` effectue
uniquement des GET anonymes. Exemples **staging uniquement** :

```sh
node extensions/Wikidebates/maintenance/probeSemanticHttp.cjs \
	--base=https://staging-fr.example.invalid \
	--title="Argument de test" \
	--iterations=3 \
	--delay-ms=1000 \
	--out=/tmp/wd-smw-staging-before.json
```

Après la modification sur staging, utiliser `--baseline` et
`--expect-html` sur le marqueur visible dans le HTML complet,
puis tester séparément `--expect-fragment` sur une modification
visible dans le fragment. La section « Débats parents » n'est pas
rendue dans le fragment ; son absence est intentionnelle.

## 7. Mesures et critères de décision

Sur **le même clone** et les mêmes pages :

```sh
php maintenance/run.php Wikidebates:MeasureSemanticCacheGuard \
	--wiki=fr --title="Dieu est une invention" \
	--samples=20 --warmups=3 --json
php maintenance/run.php Wikidebates:MeasureSemanticCacheGuard \
	--wiki=fr --title="Dieu existe-t-il ?" \
	--samples=20 --warmups=3 --json
```

Comparer p50/p95 avant/après, cache HIT effectif, CPU, SQL,
latence totale HTTP et erreurs. Les mesures publiques de référence
du 21 novembre 2026 donnent environ 0,87 ms de médiane pour le
garde-fou de l'argument « Dieu est une invention » **sans suivi actif**
(lecture seule, pas une mesure HTTP).

Validation bloquée tant que l'une des conditions suivantes existe :
profil `_ASK` absent, requête sans dépendances SQL certifiables,
HTML ancien visible après une modification externe, en-têtes CDN
non maîtrisés, pages publiques servies depuis des fichiers
sémantiques non gardés, ou charge HTTP/SQL excessive.

## 8. Retour arrière et état de la production

En cas d'anomalie, remettre **sur staging seulement**
`WikidebatesSemanticCacheTracking=false`, conserver
`EagerPurge=false`, déconnecter les workers de test, puis
restaurer l'instantané de la copie de préproduction avant de
réessayer. Ne pas tenter de résoudre un problème en purgeant
massivement le wiki public.

Ce document et la PR de ferme n°20 ne provisionnent pas un
environnement de staging. Ils préparent un **code épinglé et une
procédure vérifiable**. La PR core n°50 reste distincte et en
brouillon ; ses mécanismes de repli ne sont pas requis pour ce pilote.
