# Gestion des branches et integration Wikidebates

## Reference

- `main` est la **branche de developpement canonique sans date**, creee depuis la reconciliation des PR #68 et #69 le 22 novembre 2026.
- `main` est egalement la **branche par defaut GitHub**, verifiee le 22 novembre 2026. Le commit canonique au moment de la verification est `4f68e5f325329a78054141069085ce7ac9c7467f`.
- `wikidebates-REL1_43-stable-2026-07-09` est une branche historique encore conservee, synchronisee sans force-push avec `main` sur le meme commit au moment de la verification. Ne pas la supprimer avant verification des automates et references de deploiement FR/EN.
- Les branches datees sont historiques ; preferer des tags de publication immuables (`v1.43.11-wikidebates-YYYYMMDD`) et des branches correctives depuis `main` plutot que de nouvelles branches stables datees.
- Les nouvelles PR doivent cibler `main`. Les quatre anciens workflows (Flow, Interlanguage et deux PageForms) surveillent encore les `push` sur l'ancienne branche historique comme filet de securite **tant que les deployements FR/EN n'ont pas ete verifies** ; les trois nouveaux workflows (inspection, integration interextensions, audit des dependances) surveillent `main` uniquement. Retirer les anciens declencheurs seulement apres confirmation du basculement operationnel.
- La bascule de la branche par defaut est confirmee, mais la protection de `main` **n'est pas encore active** (`protected=false`, aucun ruleset au 22 novembre 2026). Configurer une regle avec refus des suppressions et des force-push, revue des PR et checks necessaires, puis verifier Dependabot, robots, deploiements, chemins de checkout et toutes references explicites a la branche datee. Ces reglages exigent un administrateur GitHub et ne sont pas modifies par les PR de code.
- Ne pas deplacer les sous-modules avec `git submodule update --remote` : leurs SHAs doivent etre mis a jour par des PR explicites apres tests.

- Reconciliation tracee dans la PR #68. Le choix des fichiers lors de cette reconciliation privilegie les versions recentes de `07-11`, sans rejouer les commits PageForms d'octobre devenus incompatibles avec l'architecture asynchrone de novembre.
- Les branches de fonctionnalites fusionnees par squash ou merge ne doivent pas etre remises automatiquement en oeuvre uniquement parce que leurs historiques Git divergent.

## Comportements a conserver

- `Wikidebates/src/AutoParentLink.php` : ajout synchrone du lien au parent depuis `PageSaveComplete`, avec controle de conflit, nouvelle tentative bornee et protection contre les doublons.
- `Flow` : extension conservee ; rendu des discussions complet, sans reactiver la PR #7 de topic list allegee abandonnee.
- `PageFormsWikidebates` : architecture de soumission asynchrone, widgets progressifs et tests recents ; ne pas restaurer par cherry-pick les fichiers anterieurs a ce refactoring.

## Sous-modules

- Les *gitlinks* (SHAs versionnes par la ferme) sont la source de verite pour une version reproductible. Utiliser `git submodule update --init --recursive` sans `--remote` lors d'un deploiement controle.
- `extensions/VEForAll` est epingle sur `35090688defd17b7650a30dc3d5b37da09d170dc` ; `.gitmodules` suit desormais `main` dans `wikidebates/wikidebates-veforall`. Le fork VEForAll utilise encore `wikidebates-REL1_44` comme branche **par defaut GitHub** ; la bascule manuelle et la protection de son `main` sont suivies dans [VEForAll #14](https://github.com/wikidebates/wikidebates-veforall/issues/14).
- `extensions/Wikidebates` est epingle sur `bb60212859c6b1be171f63158558b5d7dadc4fbb`.

## Validation et deploiement

- Huit workflows actifs sont presents : Flow, Interlanguage, PageForms JS, PageForms integration/SMW/navigateurs, inspection de deploiement en lecture seule, integration transversale de sous-modules, audit des dependances et audit public read-only des sites FR/EN. Les quatre historiques surveillent `main` et, transitoirement, l'ancienne branche lors des `push` ; les trois nouveaux surveillent `main`. Les PR sont testees selon leurs filtres de chemins ; des executions manuelles restent possibles. Le rapport hebdomadaire des dependances est programme le lundi a 04:17 UTC sur la branche par defaut.
- Verifier la CI Flow, Interlanguage, PageForms (Jest, integration SMW, Chromium et Firefox) ainsi que les tests du sous-module VEForAll. Une suite verte pour un ancien commit n'atteste pas automatiquement du commit final.
- Tester les formulaires de production FR/EN apres deploiement ; verifier les configurations `LocalSettings.php`, les modifications locales sur serveur et les sous-modules avant toute mise a jour.
- La fusion GitHub n'applique pas automatiquement les changements sur la ferme en production : les `LocalSettings.php`, cron de prechauffage, invalidations et mises a jour de sous-modules sont des etapes d'exploitation separees.
