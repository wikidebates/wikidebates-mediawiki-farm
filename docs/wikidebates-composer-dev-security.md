# Correctifs des outils PHP de développement — 22 novembre 2026

L'ancienne contrainte `mediawiki/mediawiki-codesniffer:45.0.0` imposait `squizlabs/php_codesniffer:3.10.3` vulnérable. La version **48.0.1** du standard MediaWiki supporte PHP >=8.1 tout en exigeant le CodeSniffer corrigé **3.13.6**. Le resolver Composer isolé a également choisi PHPCSExtra **1.4.0** et PHPCSUtils **1.2.3**.

Deux alertes HIGH corrigées dans le verrou :

- `squizlabs/php_codesniffer` : CVE-2026-67434 ; 3.10.3 → 3.13.6.
- `phpcsstandards/phpcsutils` : CVE-2026-65954 ; 1.0.12 → 1.2.3.

Le `composer.lock` a été reconstruit à partir des **quatre entrées exactes** calculées par Composer dans une GitHub Action jetable. L'entrée `wikimedia/equivset` a été **volontairement conservée** : elle provient de `composer.local.json` via `wikimedia/composer-merge-plugin` et la résolution CI `--no-install` ne chargeait pas encore ce plugin. On ne supprime pas une dépendance requise par le déploiement uniquement parce qu'un résolveur lancé sans les plugins ne l'avait pas vue.

Après mise à jour, exécuter `composer install --no-interaction --prefer-dist`, puis `composer audit --locked --abandoned=report`. Le paquet `doctrine/cache` est marqué **abandonné** : toujours signalé par l'audit, son retrait exige une validation distincte. Avec `--abandoned=report`, les CVE font échouer le job, mais le seul abandon (sans CVE) ne provoque pas un faux échec de sécurité.

Aucune modification du code de production, des caches SMW, de Flow ou d'AutoParentLink. Suivi de sécurité : [#81](https://github.com/wikidebates/wikidebates-mediawiki-farm/issues/81).
