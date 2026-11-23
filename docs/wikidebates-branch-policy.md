# Politique Git et deploiement de Wikidebates

## Branches de reference

- `main` est la branche canonique et la branche par defaut de `wikidebates-mediawiki-farm`, `wikidebates-core` et `wikidebates-veforall` (verification GitHub du 23 novembre 2026).
- Toute nouvelle pull request de maintenance vise `main`. Utiliser une branche de travail courte, puis preferer des tags de publication non deplaces aux branches stables datees.
- Ne pas supprimer automatiquement les branches historiques ou de travail : verifier leur utilisation dans les deploiements, les PR ouvertes et les autres chantiers. Une branche fusionnee par squash peut diverger de `main` sans contenir de changements a rejouer.
- Le serveur `dialectica` a ete verifie sur `main` le 23 novembre 2026 ; son commit doit etre releve de nouveau avant tout deploiement, car d'autres chantiers publient en parallele sur GitHub. Les declencheurs `push` historiques ont ete retires de Flow, PageForms et du controle du sous-module Wikidebates ; les controles sur `main`, `pull_request` et `workflow_dispatch` (quand il existe) sont conserves.
- Le workflow Interlanguage garde provisoirement son declencheur historique ; sa rationalisation appartient au chantier Interlanguage independant. Ne supprimer aucune branche historique sans verifier tous les usages restants.

## Protections GitHub

Le 23 novembre 2026, un ruleset `protect-main` actif existe dans chacun des trois depots principaux. Les trois rulesets :
- ciblent la branche par defaut ;
- interdisent la suppression et le force-push ;
- imposent le passage par une pull request et la resolution des fils de revue ;
- ne demandent actuellement **aucune approbation** (`required_approving_review_count: 0`) ;
- imposent les controles GitHub Actions listes ci-dessous, sans exiger la mise a jour prealable des branches de PR.

Les controles CI obligatoires sont verifies directement dans les trois rulesets `protect-main` au 23 novembre 2026. Les autres workflows restent executes selon leurs declencheurs ; leurs resultats doivent etre examines pour les changements concernes, mais ne sont pas tous des conditions administratives de fusion.

### Controles CI obligatoires actifs

Les checks suivants sont actuellement imposes aux PR par les rulesets `protect-main` ; ils proviennent de GitHub Actions :

- Ferme : `farm-baseline` (workflow `main-required-baseline.yml`, sans filtre de chemins sur les PR).
- Wikidebates Core : `php (8.4)`, `mediawiki-143 (8.4)`, `javascript` et `browser` (workflow `native-html.yml`, execute sur toutes les PR).
- VEForAll : `jest` (workflow `veforall-lazy-checks.yml`, execute sur toutes les PR).

Ne pas rendre obligatoires les jobs des workflows dont l'evenement `pull_request` utilise `paths` : ils ne se lancent pas sur toutes les PR et pourraient empecher des fusions sans rapport. Les tests specialises sont conserves et leurs resultats doivent etre examines avant les fusions concernees. Modifier les rulesets dans `Settings > Rules > Rulesets > protect-main` (et non par PR), puis verifier les checks sur une PR representative. Les depots principaux suppriment automatiquement les branches apres fusion ; les branches anciennes encore presentes sont a evaluer individuellement.


## Sous-modules et reproductibilite

- Le depot de la ferme contient 53 gitlinks enregistres au 23 novembre 2026, dont un sous-module d'outillage Docker facultatif sous `extensions/CrawlerProtection/build`.
- Les gitlinks epinglent les commits deployables ; la valeur `branch` de `.gitmodules` n'actualise pas ces commits.
- `extensions/VEForAll` est epingle sur `35090688defd17b7650a30dc3d5b37da09d170dc`, avec `branch = main`.
- `extensions/Wikidebates` est epingle sur `767f16c18bc08d2f77bb87970d8cf6f6b9cf21c7`, avec `branch = main`.
- Ne jamais executer `git submodule update --remote` lors d'un deploiement. Les changements de SHA passent par des PR explicites et des tests.
- Ne pas retirer un sous-module ou modifier son URL sans avoir etabli ses usages en production et dans les scripts.

## Branches par defaut des forks d'extensions

Les forks MinervaNeue, ReplaceText, ContactPage et HitCounters conservent une branche par defaut historique, distincte de la branche `integration-*` utilisee par la ferme. La branche d'integration est en avance respectivement de 71, 39, 46 et 18 commits sur cette branche par defaut lors du controle du 23 novembre 2026. Les sous-modules restent reproductibles grace a leurs SHA epingles, mais une nouvelle PR ouverte sans selection explicite de sa base risque de cibler l'ancienne branche. Il reste a confirmer la politique de suivi amont de chaque fork avant de changer sa branche GitHub par defaut ; ne pas actualiser les gitlinks pour cette seule raison.

## CI et deploiement

Les workflows couvrent notamment PHP 8.4, Flow, PageForms, Interlanguage, le cache HTML, l'integration interextensions, les sous-modules epingles, Composer et les audits publics en lecture seule. La CI ne remplace pas une validation FR/EN sur le serveur.

Le depot GitHub et le serveur `dialectica` sont deux environnements distincts. Une fusion vers `main` ne deploye rien. Avant toute synchronisation du serveur :

1. Relever `git status -sb`, les fichiers modifies et les fichiers non suivis, y compris dans les sous-modules.
2. Comparer le commit local et le commit `main` vise, ainsi que les gitlinks attendus.
3. Preserver les modifications locales et fichiers non suivis avant toute operation ; ne pas utiliser `git reset --hard`, `git clean` ni `git submodule update --force` comme raccourci.
4. Effectuer ensuite une mise a jour controlee vers un SHA valide, puis `git submodule update --init --recursive` uniquement lorsque l'absence de conflit local est etablie.
5. Verifier les configurations, les tests et les deux wikis publics. Ne pas modifier `LocalSettings.php`, les crons, la base de donnees ni les caches lors d'une simple synchronisation Git.

Les scripts d'inspection en lecture seule sont a privilegier avant le deploiement. Les sous-modules optionnels doivent etre distingues des dependances indispensables.

## Limites du chantier

Ne pas amalgamer la rationalisation Git avec les changements fonctionnels d'Interlanguage, du cache Vector/Minerva/Lecture, de Flow, de PageForms ou d'AutoParentLink. Conserver leurs tests et leurs mecanismes de securite. Les PR correspondantes suivent leurs propres validations.
