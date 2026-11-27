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

Uniquement après succès du lot 2 et contrôle des usages de Lua par
les autres applications du serveur :

- Retirer les deux gitlinks Scribunto et VariablesLua avec leurs entrées
  .gitmodules ; retirer mediawiki/semantic-scribunto du manifeste Composer
  et régénérer le lock sans mettre à jour les autres dépendances.
- Adapter les contrôles de CI et supprimer les automatismes de surveillance
  LuaSandbox après désinstallation effective de cette surveillance sur
  le serveur. Utiliser le script scripts/luasandbox/uninstall.sh de la
  version encore installée (en root, sauvegarde incluse).
- Retirer les vérifications et paramètres Lua devenus inutiles dans la
  configuration locale et sitemap.sh. Ne pas supprimer les paquets PHP
  ou fichiers de configuration LuaSandbox avant d'avoir confirmé qu'ils
  ne sont utilisés par aucun autre site ni version PHP.
- Conserver Variables, SemanticMediaWiki, les révisions et les données
  sémantiques. Les anciens modules Lua dans les tests du core sont des
  oracles, pas des dépendances d'exécution.

Ces étapes sont des portes de validation distinctes : ce lot Git ne
désactive, ne désinstalle et ne déploie rien sur le serveur.
