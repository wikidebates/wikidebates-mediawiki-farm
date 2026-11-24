# Migration d'Interlanguage vers un sous-module Git

Cette procédure ne s'applique qu'après fusion de la PR de migration, validation
des tests et choix explicite du commit du déploiement groupé. Ne pas exécuter
sur la production avant cette validation. Aucun changement de schéma, purge
ou reconstruction des caches n'est prévu pour la migration de dépôt.

Le code reste chargé depuis `extensions/Interlanguage` ; les chemins et
paramètres LocalSettings.php ne changent pas. Le nouveau gitlink doit
référencer un commit du dépôt public `wikidebates/Interlanguage` sur main.

## Préparation et contrôles

Dans la racine Git de la ferme, pendant une fenêtre de déploiement prévue,
utiliser le compte habituel de déploiement. La procédure ci-dessous suppose
que le remote de la ferme s'appelle `wikidebates` et que la branche locale
est main. Adapter ces deux prérequis explicitement si nécessaire.

```bash
set -euo pipefail
test "$(git branch --show-current)" = main
old=$(git rev-parse HEAD)
git fetch --no-recurse-submodules wikidebates main
target=$(git rev-parse wikidebates/main)
printf 'Avant : %s\nCandidat : %s\n' "$old" "$target"
git merge-base --is-ancestor "$old" "$target"
git ls-tree "$old" -- extensions/Interlanguage
git ls-tree "$target" -- extensions/Interlanguage
test "$(git ls-tree "$old" -- extensions/Interlanguage | awk '{print $1}')" = 040000
test "$(git ls-tree "$target" -- extensions/Interlanguage | awk '{print $1}')" = 160000
test -z "$(git status --porcelain --untracked-files=no)"
test -z "$(git status --porcelain --untracked-files=all --ignored -- extensions/Interlanguage)"
test ! -e "$(git rev-parse --git-path modules/extensions/Interlanguage)"
git show "$target:.gitmodules" | grep -A 3 -F '[submodule "extensions/Interlanguage"]'
git diff --stat "$old" "$target"
```

Vérifier manuellement que le candidat est exactement le commit de release
approuvé et que le nouveau gitlink désigne le commit autonome validé. Si un
contrôle échoue (fichier local ignoré ou non suivi, modification locale,
ancien dépôt résiduel, divergence), arrêter : ne pas utiliser
`git reset --hard`, `git clean`, `git rm -f` ni de force-push.

Conserver une copie complète du répertoire actuel hors de l'arbre servi
par Apache, avec des permissions privées :

```bash
umask 077
backup=$(mktemp -d "$HOME/interlanguage-migration.XXXXXXXX")
cp -a extensions/Interlanguage "$backup/Interlanguage"
diff -qr extensions/Interlanguage "$backup/Interlanguage"
printf '%s\n' "$old" > "$backup/old-farm-commit"
printf '%s\n' "$target" > "$backup/target-farm-commit"
printf 'Sauvegarde : %s\n' "$backup"
```

Conserver les SHA et la sauvegarde jusqu'à la fin de la recette. Préparer le
retour arrière global du déploiement groupé séparément.

## Conversion

Dans la même session, après validation manuelle du candidat et de la
sauvegarde, exécuter uniquement une mise à jour fast-forward, puis
initialiser le sous-module à son commit épinglé (jamais `--remote`) :

```bash
test "$(git rev-parse HEAD)" = "$old"
test -z "$(git status --porcelain --untracked-files=no)"
test -z "$(git status --porcelain --untracked-files=all --ignored -- extensions/Interlanguage)"
git -c submodule.recurse=false merge --ff-only "$target"
git submodule sync -- extensions/Interlanguage
git submodule update --init --checkout -- extensions/Interlanguage
expected=$(git ls-tree HEAD -- extensions/Interlanguage | awk '{print $3}')
test "$(git -C extensions/Interlanguage rev-parse HEAD)" = "$expected"
test -z "$(git -C extensions/Interlanguage status --porcelain)"
git -C extensions/Interlanguage remote -v
git submodule status -- extensions/Interlanguage
git status --short --branch
```

Une transition arbre suivi vers gitlink propre est réalisée par Git ;
aucune suppression forcée du répertoire n'est nécessaire. En cas d'échec,
arrêter et conserver la sauvegarde. Ne pas lancer d'autres commandes
destructives. Les autres sous-modules suivent la procédure habituelle du
déploiement groupé, à leurs pins respectifs.

## Recette

Sur le serveur, vérifier les versions PHP/MediaWiki prévues, le gitlink
et l'absence de modifications locales. Vérifier la disponibilité HTTP et
comparer les réponses de l'API `prop=langlinks` sur des couples FR/EN
connus, y compris pages ordinaires, institutionnelles, débats et arguments.
Contrôler le sélecteur de langue et les erreurs PHP/MediaWiki. Les requêtes
d'observation ne doivent pas déclencher de purge explicite. Ne pas éditer
des pages de production uniquement pour tester cette migration ; les essais
d'écriture et de propagation sont réservés à l'installation jetable.

## Retour arrière

Si la conversion ou la recette échoue, arrêter le déploiement groupé et
suivre son plan de retour arrière. Ne pas déplacer main en arrière.
Avant de désinitialiser le nouveau sous-module, vérifier qu'il ne contient
aucune modification ni donnée locale ; sauvegarder tout état inattendu.
La désinitialisation sans force d'un sous-module propre, puis le checkout
détaché de l'ancien commit de la ferme, restaurent le mode historique.
Cette opération concerne potentiellement tous les changements du
déploiement groupé : vérifier les autres pins et appliquer leur procédure
de restauration également. Elle ne doit être exécutée qu'après décision
explicite de revenir à la release précédente.

```bash
test -z "$(git -C extensions/Interlanguage status --porcelain --untracked-files=all --ignored)"
test -z "$(git status --porcelain --untracked-files=no)"
git submodule deinit -- extensions/Interlanguage
git -c submodule.recurse=false switch --detach "$old"
test "$(git rev-parse HEAD)" = "$old"
diff -qr extensions/Interlanguage "$backup/Interlanguage"
```

Si Git refuse un contrôle, ne pas forcer. Le répertoire de sauvegarde reste
disponible et la branche main conserve le commit de release. L'état final
des autres sous-modules doit être vérifié avant réouverture du site.

Aucun changement de comportement, ajout hreflang, nettoyage d'historique
ou modification de cache ne fait partie de cette procédure.
