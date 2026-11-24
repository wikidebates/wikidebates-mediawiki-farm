# Surveillance et réparation de LuaSandbox

Dispositif d'administration du serveur Wikidébats, indépendant de MediaWiki,
du cron KeyHelp et de la génération des sitemaps. Cible vérifiée :
Debian 13, PHP 8.4 fourni par Debian, service `php8.4-fpm.service`,
paquet `php-luasandbox`. L'installation initiale de ce paquet a été
validée en production (CLI : exécution de Lua, configuration FPM : module
activé). Le dispositif automatique lui-même doit être testé sur le serveur.

## Installation ou mise à jour

**Sécurité :** exécuter uniquement depuis une copie revue et contrôlée
par root, hors du répertoire web et non modifiable par webmaster.
Ne jamais lancer un script en root directement depuis le checkout de
production, ni utiliser `curl | bash`. Télécharger ou extraire les fichiers
d'une révision GitHub vérifiée dans un répertoire privé root, puis examiner
leur contenu. Les scripts ne téléchargent pas de code GitHub automatiquement.

```bash
bash -n install.sh
bash -n wikidebates-luasandbox-check
bash -n uninstall.sh
bash install.sh
```

L'installateur vérifie et répare si nécessaire, marque le paquet comme
installé manuellement (`apt-mark manual`, protection contre autoremove),
puis sauvegarde les anciens fichiers dans un répertoire privé
`/root/wikidebates-luasandbox-backup.*`. Les deux fichiers sont remplacés
atomiquement, chacun sur son propre système de fichiers :

- `/usr/local/sbin/wikidebates-luasandbox-check` : root:root, mode 0755.
- `/etc/cron.d/wikidebates-luasandbox` : root:root, mode 0644.

Le cron s'exécute à la minute 45 de chaque heure, notamment à 23 h 45,
avant la maintenance quotidienne prévue à 0 h 01 (heure du serveur).
Aucune modification du cron KeyHelp ni de la configuration Apache.
Ne pas installer un second cron équivalent. L'installation est répétable ;
elle conserve une sauvegarde des fichiers remplacés à chaque passage.

## Fonctionnement

- Vérifie la présence du paquet, exécute réellement Lua dans PHP CLI 8.4
  (le test doit retourner l'entier 42) et vérifie le chargement du module
  dans la configuration de PHP-FPM.
- Installe le paquet s'il manque ; tente d'abord phpenmod si le module est
  désactivé ; réinstalle le paquet si nécessaire, y compris les fichiers
  de configuration manquants fournis par dpkg.
- Vérifie le module dans les mappings du processus maître FPM actif.
  Si nécessaire, teste la configuration FPM puis demande un reload,
  et vérifie le résultat pendant au plus 30 secondes. Ce contrôle ne
  constitue pas un test HTTP de chaque pool FPM.
- Utilise un verrou indépendant dans /run et attend au plus 120 secondes
  le verrou dpkg. Un cron concurrent est ignoré ; l'installation attend
  le verrou de surveillance et échoue s'il reste indisponible.
- Aucun changement lorsque le paquet et les modules sont sains. Les
  réparations et erreurs sont journalisées ; les erreurs donnent un
  code de sortie non nul. Le cron peut également envoyer ses sorties
  si la distribution du courrier local est configurée.

Les réparations emploient les dépôts APT configurés, sans apt update,
upgrade général, autoremove ni suppression de paquets. APT peut néanmoins
installer ou mettre à niveau des dépendances nécessaires et déclencher
les hooks PHP du paquet. Aucun contournement des verrous APT/dpkg.
Un dépôt inaccessible, une incompatibilité de PHP, une configuration
endommagée ou un service FPM arrêté nécessitent une intervention manuelle.
La cause historique de la disparition du paquet n'est pas établie.
Le contrôle n'empêche pas toute désinstallation et ne garantit pas une
réparation immédiate entre deux passages. Conserver aussi le contrôle
LuaSandbox dans sitemap.sh. Revoir le dispositif avant toute migration
vers une autre version de PHP ou une installation PHP KeyHelp distincte.

## Diagnostic (root)

```bash
/usr/local/sbin/wikidebates-luasandbox-check --wait
/usr/bin/php8.4 --ri luasandbox
/usr/bin/php8.4 -r '$lua = new LuaSandbox(); var_dump($lua->loadString("return 42")->call());'
/usr/sbin/php-fpm8.4 -m | grep -x luasandbox
systemctl is-active php8.4-fpm.service
journalctl -t wikidebates-luasandbox
zgrep -i luasandbox /var/log/apt/history.log* /var/log/dpkg.log*
apt-mark showmanual | grep -x php-luasandbox
```

Un contrôle sain retourne 0 et reste silencieux hors terminal interactif.
Pour vérifier le cron, contrôler aussi ses journaux et son fichier dans
/etc/cron.d ; ne pas supprimer volontairement LuaSandbox en production.

## Désinstallation et retour arrière

Depuis une copie de confiance contrôlée par root :

```bash
bash uninstall.sh
```

Sauvegarde puis retire uniquement le cron et le script de surveillance,
sans désinstaller LuaSandbox, sans modifier PHP et sans effacer les
journaux ou les sauvegardes. Le paquet reste marqué manuel.

Pour restaurer une version antérieure, choisir et vérifier une sauvegarde
précise, en root, puis réinstaller les fichiers présents avec les modes
0755 et 0644 respectivement. Si aucun fichier n'existait avant,
désinstaller le dispositif. Ne pas restaurer un ancien cron PHP 8.3.

## Tests

Le workflow GitHub vérifie la syntaxe des trois scripts et teste le contrôle
avec des commandes simulées. Les tests ne réalisent ni installation APT
réelle, ni accès à un serveur, ni rechargement d'un service.
