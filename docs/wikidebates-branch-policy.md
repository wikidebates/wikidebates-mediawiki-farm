# Politique Git et deploiement de Wikidebates

## Branches de reference

- `main` est la branche canonique et la branche par defaut de `wikidebates-mediawiki-farm`, `wikidebates-core` et `wikidebates-veforall` (verification GitHub du 23 novembre 2026).
- Toute nouvelle pull request de maintenance vise `main`. Utiliser une branche de travail courte, puis preferer des tags de publication non deplaces aux branches stables datees.
- Ne pas supprimer automatiquement les branches historiques ou de travail : verifier leur utilisation dans les deploiements, les PR ouvertes et les autres chantiers. Une branche fusionnee par squash peut diverger de `main` sans contenir de changements a rejouer.
- Les workflows Flow, Interlanguage et PageForms gardent temporairement un declencheur `push` sur `wikidebates-REL1_43-stable-2026-07-09` ; `wikidebates-cache-submodule.yml` conserve aussi un declencheur sur `wikidebates-REL1_43-stable-2026-07-11`. Ne retirer ces declencheurs qu'apres verification des usages reels.

## Protections GitHub

Le 23 novembre 2026, un ruleset `protect-main` actif existe dans chacun des trois depots principaux. Les trois rulesets :
- ciblent la branche par defaut ;
- interdisent la suppression et le force-push ;
- imposent le passage par une pull request et la resolution des fils de revue ;
- ne demandent actuellement **aucune approbation** (`required_approving_review_count: 0`) ;
- ne declarent **aucun controle CI obligatoire** (`required_status_checks` absent).

Les workflows GitHub Actions continuent de s'executer selon leurs propres declencheurs, mais leurs resultats ne constituent donc pas une condition de fusion imposee par ces rulesets. Toute modification de cette politique doit etre faite dans les parametres GitHub du depot et testee avec des noms de checks stables, notamment lorsque les workflows utilisent des filtres de chemins.

## Sous-modules et reproductibilite

- Le depot de la ferme contient 52 gitlinks enregistres au 23 novembre 2026, dont un sous-module d'outillage Docker facultatif sous `extensions/CrawlerProtection/build`.
- Les gitlinks epinglent les commits deployables ; la valeur `branch` de `.gitmodules` n'actualise pas ces commits.
- `extensions/VEForAll` est epingle sur `35090688defd17b7650a30dc3d5b37da09d170dc`, avec `branch = main`.
- `extensions/Wikidebates` est epingle sur `daf094a0a7ba7612d3fb782b9c1d762d5032cffe`, avec `branch = main`.
- Ne jamais executer `git submodule update --remote` lors d'un deploiement. Les changements de SHA passent par des PR explicites et des tests.
- Ne pas retirer un sous-module ou modifier son URL sans avoir etabli ses usages en production et dans les scripts.

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
