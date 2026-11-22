# Audit régulier des dépendances Git et Composer

L'inventaire du 22 novembre 2026 est suivi dans [l'issue #78](https://github.com/wikidebates/wikidebates-mediawiki-farm/issues/78) : 53 sous-modules, dont 44 externes (42 références différentes du sommet de leur branche, 2 identiques). Les neuf forks Wikidebates étaient épinglés sur leur branche suivie via le connecteur GitHub autorisé.

Le workflow `pinned-dependencies-audit.yml` produit un rapport **en lecture seule** pour les 53 gitlinks (SHA épinglé, branche suivie et SHA amont) et exécute `composer audit --locked --abandoned=report` sur les versions PHP réellement verrouillées. Les résultats sont disponibles dans le résumé du job et l'artifact JSON, sans écrire de commit, déployer, purger ou changer un sous-module.

**Limites :** les branches Gerrit sont vérifiées via les miroirs Wikimedia GitHub ; ces miroirs peuvent avoir du retard. Le jeton Actions de la ferme ne peut pas accéder au fork privé MinervaNeue sans autorisation inter-dépôts. Une divergence de SHA n'est ni une preuve de faille ni une obligation de mise à jour. Les alertes de sécurité font échouer la CI. Les paquets seulement **abandonnés** sont signalés mais ne font pas échouer le job, afin de les migrer séparément ; voir l'issue #81. Toute mise à jour passe par une PR et des tests sous MediaWiki 1.43.

Le déclencheur hebdomadaire `schedule` n'exécutera ce workflow de `main` qu'une fois `main` définie comme branche par défaut dans les paramètres GitHub. Suivi administratif : [#71](https://github.com/wikidebates/wikidebates-mediawiki-farm/issues/71).

**Hors champ :** aucune modification des caches SMW ou de leurs options. La fraîcheur sémantique reste différée : [Wikidebates Core #61](https://github.com/wikidebates/wikidebates-core/issues/61).

## Déclencheurs lors des mises à jour de sous-modules

Depuis la PR #93, l'audit se déclenche lors de **toute modification de gitlink**, notamment `extensions/Wikidebates`, `extensions/VEForAll`, les forks `skins/*` et le gitlink imbriqué `extensions/CrawlerProtection/build`. Le filtre GitHub `extensions/*` vise un chemin de premier niveau (un gitlink), **pas** tous les fichiers PHP sous `extensions/Flow/**`, ce qui évite de lancer cet audit à chaque modification d'une extension incluse dans la ferme.

Le rapport hebdomadaire continue de fonctionner sur `main`. Les changements ne mettent jamais automatiquement à jour de sous-modules, et n'activent aucun mécanisme de fraîcheur SMW.
