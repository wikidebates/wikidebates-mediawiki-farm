# Retrait progressif du moteur Lua

Les audits de novembre 2026 ont examiné les six wikis. Seuls FR et EN
contiennent encore des sources Scribunto. Le consommateur dynamique actif
identifié est le survol ArgumentMap, migré vers #argumentmap:render
dans Wikidebates core PR #156. Les autres modèles suspects doivent encore
être contrôlés individuellement avant une modification de contenu.

## Lot 1 : déploiement PHP/JavaScript

Épingler le commit core validé dans extensions/Wikidebates. Ne changer
ni les sous-modules Lua, ni composer.json/lock, ni LocalSettings.php,
ni les scripts système à ce stade. La CI de la ferme réutilise les
cas d'intégration ArgumentMap du core avec la version exacte de SMW
verrouillée par Composer et sans charger de moteur Lua, avec et sans le gestionnaire d'archives. Les tests
interextensions habituels restent actifs.

Suivre la politique Git existante : inspectFarmDeployment.sh, vérification
du commit attendu, arbre et sous-modules sans changements locaux, mise à
jour fast-forward, puis git submodule update --init --recursive (sans
--force ni --remote). Ne pas appliquer une mise à jour Composer non liée.
La validation Git seule ne suffit pas à établir la version PHP-FPM.

Sur FR et EN, vérifier les trois variantes de survol : carte complète,
parent débat (POUR/CONTRE), parent argument (justifications/objections).
Dans les outils réseau du navigateur, confirmer que le texte envoyé à
action=parse commence par #argumentmap:render, et non #invoke.
Vérifier les libellés, liens, surlignage, données vides et erreurs API.
Conserver l'ancien moteur jusqu'à validation de ce lot.

Ne pas effectuer de purge générale de caches dans ce lot. Les anciens
onglets et scripts ResourceLoader encore en cache peuvent émettre des
appels Lua ; après retrait définitif, un ancien onglet devra être rechargé.
Une compatibilité indéfinie avec les vieux clients n'est pas promise.

## Lot 2 : archivage et désactivation

Wikidebates core PR #157 est également fusionnée et épinglée dans ce lot.
Le gestionnaire optionnel WikidebatesLuaArchive peut conserver le modèle Scribunto et
les espaces 828/829 sans exécuter de Lua. Voir la documentation
extensions/Wikidebates/docs/lua-archive.md pour les détails.

Sur FR/EN seulement, sauvegarder la configuration et la base, vérifier
les usages restants (dont le modèle Fix), puis remplacer ensemble les
trois chargements Scribunto, VariablesLua et SemanticScribunto par celui
de l'archive. Ne jamais activer l'archive et Scribunto simultanément.
Les quatre autres wikis conservent leur configuration actuelle.
Tester d'abord sur une copie, puis vérifier les sources publiques,
oldid, différences de versions, permissions et infobulles. La conservation
du code source ne garantit pas l'ancien rendu des pages qui invoquaient Lua.
Les fichiers et dépendances Lua restent disponibles pour un retour arrière
pendant cette validation ; aucun changement SQL manuel n'est requis.

## Lot 3 : retrait physique

Ne pas fusionner ni déployer ce lot avant d'avoir validé les étapes 1 et 2
en production et vérifié les autres usages de Lua sur le serveur. Il ne
doit rester aucune déclaration de chargement des trois anciennes extensions
dans les configurations effectivement utilisées.

Avant le changement Git, sauvegarder les configurations et les anciens
répertoires des extensions, et désinstaller la surveillance LuaSandbox
avec le script encore présent dans la version installée :

```sh
sudo bash scripts/luasandbox/uninstall.sh
```

Ce script sauvegarde les fichiers de surveillance et retire seulement le
cron et le script système ; il conserve les paquets PHP. Ne jamais utiliser
une suppression récursive ou forcée pour faire disparaître un sous-module
présent sur le serveur. Vérifier les éventuelles modifications locales et
les conserver avant de désinitialiser les deux sous-modules retirés.
Conserver leurs dépôts Git pour un retour arrière. Les éventuels fichiers
résiduels ne doivent pas être effacés sans inventaire.

Ce lot retire les gitlinks Scribunto et VariablesLua et leurs entrées
dans .gitmodules, le paquet mediawiki/semantic-scribunto (uniquement),
sa surveillance de versions, et les outils de surveillance LuaSandbox.
Tous les autres paquets et références de composer.lock sont conservés.
La CI vérifie le manifeste et le lock avec Composer, leur installation
et les moteurs natifs, avec et sans le gestionnaire d'archives.
Installer le nouveau lock avec `composer install` suivant la politique
de déploiement existante, jamais avec `composer update` sur le serveur.

Retirer les anciens paramètres et contrôles Lua dans LocalSettings.php
et sitemap.sh. La suppression du paquet système LuaSandbox n'est pas
automatisée : vérifier séparément son utilisation dans les autres sites
et dans chaque version PHP, ainsi que les services PHP-FPM concernés.
Conserver Variables, SemanticMediaWiki, WikidebatesLuaArchive sur FR/EN,
les révisions et les données sémantiques. Aucun changement SQL manuel.
Les modules Lua utilisés comme oracles de tests du core restent en place.

Les contrôles finaux doivent confirmer les versions de toutes les
dépendances, le fonctionnement des six wikis, les trois modes de survol,
les historiques et la visibilité des révisions, ainsi que l'absence
d'erreur PHP ou de cron. Restaurer si nécessaire le commit du lot 1,
les trois anciennes extensions, le lock et la configuration sauvegardée ;
ne pas charger simultanément Scribunto et l'archive.

Ce lot Git ne désactive ni ne désinstalle quoi que ce soit sur le serveur.
