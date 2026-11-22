# Gestion des branches et integration Wikidebates

## Reference

- `main` est la **branche de developpement canonique sans date**, creee depuis la reconciliation des PR #68 et #69 le 22 novembre 2026.
- `wikidebates-REL1_43-stable-2026-07-09` reste la branche par defaut GitHub **jusqu'au changement explicite du reglage Settings > General > Default branch** ; ne pas la supprimer avant verification des automatisations et references de deploiement.
- Les branches datees sont historiques ; preferer des tags de publication immuables (`v1.43.11-wikidebates-YYYYMMDD`) et des branches correctives depuis `main` plutot que de nouvelles branches stables datees.
- Pendant la transition, les CI `push` surveillent **a la fois** `main` et l'ancienne branche par defaut ; toutes les PR doivent cibler `main` des la bascule.
- Lors de la bascule, verifier les protections de branche, les exigences CI, Dependabot, robots, deploiements, chemins de checkout et toutes references explicites a la branche datee.
- Ne pas deplacer les sous-modules avec `git submodule update --remote` : leurs SHAs doivent etre mis a jour par des PR explicites apres tests.

- Reconciliation tracee dans la PR #68. Le choix des fichiers lors de cette reconciliation privilegie les versions recentes de `07-11`, sans rejouer les commits PageForms d'octobre devenus incompatibles avec l'architecture asynchrone de novembre.
- Les branches de fonctionnalites fusionnees par squash ou merge ne doivent pas etre remises automatiquement en oeuvre uniquement parce que leurs historiques Git divergent.

## Comportements a conserver

- `Wikidebates/src/AutoParentLink.php` : ajout synchrone du lien au parent depuis `PageSaveComplete`, avec controle de conflit, nouvelle tentative bornee et protection contre les doublons.
- `Flow` : extension conservee ; rendu des discussions complet, sans reactiver la PR #7 de topic list allegee abandonnee.
- `PageFormsWikidebates` : architecture de soumission asynchrone, widgets progressifs et tests recents ; ne pas restaurer par cherry-pick les fichiers anterieurs a ce refactoring.

## Sous-modules

- Les *gitlinks* (SHAs versionnes par la ferme) sont la source de verite pour une version reproductible. Utiliser `git submodule update --init --recursive` sans `--remote` lors d'un deploiement controle.
- `extensions/VEForAll` est epingle sur `35090688defd17b7650a30dc3d5b37da09d170dc` ; la branche suivie par `.gitmodules` est `wikidebates-REL1_43-stable-2026-11-22` dans `wikidebates/wikidebates-veforall`, et non l'ancienne ligne `wikidebates-REL1_44`.
- `extensions/Wikidebates` est epingle sur `bb60212859c6b1be171f63158558b5d7dadc4fbb`.

## Validation et deploiement

- Les quatre workflows principaux (Flow, Interlanguage, PageForms JS, PageForms integration/SMW/navigateurs) ciblent `main` et, transitoirement, l'ancienne branche par defaut lors des `push` et continuent a tester les PR ; des executions manuelles restent possibles.
- Verifier la CI Flow, Interlanguage, PageForms (Jest, integration SMW, Chromium et Firefox) ainsi que les tests du sous-module VEForAll. Une suite verte pour un ancien commit n'atteste pas automatiquement du commit final.
- Tester les formulaires de production FR/EN apres deploiement ; verifier les configurations `LocalSettings.php`, les modifications locales sur serveur et les sous-modules avant toute mise a jour.
- La fusion GitHub n'applique pas automatiquement les changements sur la ferme en production : les `LocalSettings.php`, cron de prechauffage, invalidations et mises a jour de sous-modules sont des etapes d'exploitation separees.
