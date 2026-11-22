# Contrôle Git en lecture seule du déploiement Wikidébats

Ce contrôle sert à relever les **versions effectivement présentes dans un répertoire de déploiement**, sans les modifier. Il complète la [politique des branches](wikidebates-branch-policy.md).

## Utilisation

Sur **chacun des serveurs FR et EN**, identifier d'abord le répertoire MediaWiki réellement utilisé par le site, puis exécuter depuis ce répertoire :

```sh
bash maintenance/inspectFarmDeployment.sh
```

Le programme ne fait aucun `git fetch`, `git checkout`, `git submodule update`, appel HTTP, accès SQL, purge, ni opération sur cron. Il ne lit ni n'affiche les secrets de `LocalSettings.php`.

Il affiche la branche et le SHA du dépôt, la version MediaWiki déclarée, le nombre de fichiers suivis modifiés et le bilan des sous-modules présents par rapport aux **gitlinks** enregistrés dans le commit. Il vérifie aussi la présence de Flow, Interlanguage, PageForms et du fichier AutoParentLink dans le sous-module Wikidebates.

Code de sortie : `0` si l'arbre suivi et tous les sous-modules **nécessaires au wiki** sont cohérents ; `1` si une anomalie doit être examinée ; `2` si le répertoire n'est pas le bon dépôt Git. Une copie de CI sans sous-modules initialisés renverra normalement `1`.

**Exception explicite :** `extensions/CrawlerProtection/build` est le sous-module `gesinn-it-pub/docker-compose-ci`, requis par le **Makefile Docker des tests de CrawlerProtection**, mais **pas par son code PHP en production**. Une absence de ce dossier est affichée comme `OPTIONAL_UNINITIALIZED` et n'empêche pas un résultat `GIT CHECK OK` si tous les autres contrôles passent. Un mauvais SHA d'un sous-module *présent*, y compris celui-ci, reste toujours signalé comme `MISMATCH` et fait échouer le contrôle.

Ce relevé vérifie la cohérence avec **le commit déjà installé**, pas sa fraîcheur par rapport à `main` sur GitHub. Un `GIT CHECK OK` ne valide pas un éventuel déploiement en retard.

**Une concordance Git ne garantit pas à elle seule que ces fichiers sont ceux chargés par PHP-FPM ni que les modifications sont déployées.** Vérifier également le document root, les configurations réellement incluses, les éventuelles modifications locales hors Git, les services et les workers.

## Vérifications complémentaires, séparées du script

- Comparer le SHA relevé avec celui de la branche `main` sur GitHub et avec la version que l'on souhaite réellement déployer ; une différence ne doit jamais entraîner une mise à jour automatique.
- Vérifier les déclarations de chargement des extensions sur les deux wikis : conserver `VEForAll`, ne pas laisser de référence active à l'ancien `VEForAllMobileDeps` supprimé du dépôt, et conserver `Flow`.
- Contrôler manuellement `LocalSettings.php`, les jobs, les éventuels crons de préparation du cache et les versions de dépendances, sans afficher de secrets dans des tickets publics.
- **Ne pas lancer** `git clean`, `git reset --hard`, `git submodule update --force`, `composer update`, des jobs ou des purges sur la production à la suite de ce relevé sans plan de déploiement séparé.

## Fraîcheur du cache SMW

Une relation SMW enregistrée correctement peut encore être présentée sous sa forme ancienne dans une autre page dont le HTML, le ParserOutput ou un fragment est déjà en cache. Ce risque n'implique **pas** un défaut d'AutoParentLink : ce dernier met le parent à jour de manière synchrone.

Les fonctionnalités `WikidebatesSemanticCacheTracking` et `WikidebatesSemanticCacheEagerPurge` doivent rester dans leur état de production actuel tant que les tests de fraîcheur n'ont pas réussi sur une copie isolée. **Ce contrôle Git n'active aucun de ces mécanismes.**

Une procédure et une sonde HTTP en lecture seule existent déjà dans le sous-module Wikidebates : [docs/smw-live-cache-validation.md](https://github.com/wikidebates/wikidebates-core/blob/main/docs/smw-live-cache-validation.md). Les changements de relations parentales nécessaires à la validation se font uniquement sur une préproduction isolée.
