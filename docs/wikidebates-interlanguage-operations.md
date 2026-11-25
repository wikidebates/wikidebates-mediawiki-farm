# Exploitation Interlanguage et audit périodique

## Référence et périmètre

Les correctifs validés sur les six bases MediaWiki 1.43.12 / PHP 8.4,
FR central, sont intégrés par les PR de la ferme #181 et #184 :
Interlanguage 77f359c1b09ff4e0d68066ce41c1e30c9c72561f,
WikiSEO e73f65035731 (préfixe). Ne pas utiliser ces références comme
instruction de retour en arrière si main a évolué.

Le relevé de production du 24 novembre 2026 comptait 3 699 entrées
supplémentaires FR/FR et 15 autres entrées orphelines. Le fichier de
sauvegarde de l'administrateur contient 3 714 entrées. La table
interlanguage_links est InnoDB, clé primaire (ill_from, ill_lang).
Ces chiffres sont un instantané et non des constantes de nettoyage.

## Mise à jour du 25 novembre 2026 : nouveau chantier de fiabilisation

Les observations historiques ci-dessous sont conservées pour traçabilité.
Elles ne décrivent plus nécessairement la configuration de production :
l'administrateur a confirmé la configuration des 30 routes, les six API
de purge, les quatre timers dédiés et les deux workers permanents, ainsi
que l'activation des notifications de fallback. Le nettoyage historique
a déjà été exécuté. Ne pas rejouer ces opérations par défaut.

Pour les recherches ponctuelles, la recette des déplacements
et suppressions, les mesures comparables et la génération des règles
hreflang, suivre [la procédure de déploiement dédiée](interlanguage-normalized-rollout.md).
Ne pas reconstruire globalement les caches pendant cette activation.

## Conditions préalables aux purges

Le relevé a trouvé seulement cinq URL API renseignées parmi les trente
couples de langues distinctes. Les cinq désignent EN. JobRunRate vaut
zéro sur les six wikis. Le relevé suivant confirme deux boucles de
runJobs déjà actives pour FR et EN (50 secondes, 200 tâches, pause 5 s).
Aucune boucle DE/ES/IT/PT n'a été observée. La file FR contenait 25 693
tâches replaceText, 113 tâches CirrusSearch et une revertedTagUpdate.
Aucune tâche purgeDependentWikis n'a été affichée. Ne pas ajouter de
runner général concurrent ni arrêter les boucles existantes. Vérifier
leur gestionnaire et prévoir uniquement les runners ciblés nécessaires.
Les 30 préfixes distants existent dans les tables SQL avec les URL
/wiki/$1 attendues ; 25 champs iw_api sont vides.

Conserver la configuration interwiki comme source de vérité. Vérifier
si InterwikiCache est statique ou si le service lit la table SQL.
Pour chaque préfixe, comparer l'URL de navigation et l'URL API du
service effectif avec la ligne SQL. Ne modifier ni iw_url, ni iw_local,
ni iw_trans, ni iw_wikiid pour corriger les seules URL API manquantes.
Ne pas remplacer une URL API non vide sans examen.

Après le déploiement des correctifs, compléter les API réellement
manquantes avec les URL vérifiées des six éditions, puis vérifier les
permissions de purge depuis le serveur. Une réponse HTTP 200 seule
ne prouve pas qu'une purge a été acceptée par MediaWiki.

Ne pas exécuter en bloc toutes les tâches anciennes : les files
peuvent contenir d'autres travaux (dont les tâches de cache sémantique).
Inspecter les anciennes tâches purgeDependentWikis avant leur exécution.
Si un runner dédié est nécessaire, utiliser le filtre
--type=purgeDependentWikis, des limites de temps et de nombre de tâches,
et un verrou empêchant le chevauchement des exécutions. Le runner doit
couvrir les six éditions pour les notifications de fallback.
N'activer InterlanguageExtensionNotifyFallbackChanges qu'après
validation de ces conditions.

Une purge ordinaire invalide le rendu ; elle ne garantit pas une
mise à jour immédiate de page_props. Ne pas élargir les droits anonymes
linkpurge pour résoudre cette question. Vérifier les propriétés et les
caches personnalisés séparément lors de la recette de production.

## Déploiement des URL API et services systemd

Le dernier relevé confirme les services système permanents
mw-jobqueue-fr.service et mw-jobqueue-en.service, sous webmaster,
dans la racine MediaWiki. Les conserver. Pour DE/ES/IT/PT, les
deux modèles ops/systemd/mw-interlanguage-purge@.service et
ops/systemd/mw-interlanguage-purge@.timer exécutent exclusivement
purgeDependentWikis, par lots de 20 et avec un budget runJobs de 30 s,
puis une pause de 5 minutes. Une même instance systemd ne se
chevauche pas. Aucun service n'est installé automatiquement.

Après le déploiement groupé, en tant que webmaster, prévisualiser
les cinq préfixes distants sur chacun des six wikis :

```bash
cd /home/users/webmaster/www/farm.wikidebates.org/w

for wiki in fr en de es it pt; do
	php maintenance/run.php ./ops/interlanguage/configureApiRoutes.php --wiki="$wiki" || break
done
```

Le script refuse les préfixes manquants, les URL de navigation
différentes, une configuration statique et les URL API non vides
inattendues. Il n'effectue aucun changement sans --apply.
Les 25 valeurs vides observées correspondent aux couples source/cible ;
si des tables sont partagées, le nombre de lignes physiques à mettre
à jour peut être inférieur.

Après vérification de toutes les prévisualisations :

```bash
(
	set -euo pipefail
	for wiki in fr en de es it pt; do
		php maintenance/run.php ./ops/interlanguage/configureApiRoutes.php --wiki="$wiki" --apply
	done
)
```

L'application exige la classe du nouveau module API, vérifie InnoDB,
verrouille les lignes du wiki pendant la transaction, sauvegarde les
valeurs réellement modifiées dans
/home/users/webmaster/files/interlanguage-audits/api-routes-*.json,
et ne remplit que iw_api encore vide. Les caches de recherche des
cinq préfixes sont invalidés après commit. Chaque wiki est traité
séparément, pas dans une transaction globale sur les six bases.
Relancer le mode prévisualisation dans de nouveaux processus :
il doit indiquer zéro champ SQL manquant et les API effectives attendues.
En cas d'échec, ne pas poursuivre l'activation des purges.

Vérifier que le module purge est autorisé depuis le serveur et
que les requêtes ne sont pas bloquées par Cloudflare. Un droit purge
annoncé par l'API userinfo ne suffit pas à prouver l'exécution ;
vérifier une purge ciblée consentie lors de la recette. Ne pas ajouter
linkpurge aux utilisateurs anonymes. Contrôler sur chaque wiki
l'enregistrement du job purgeDependentWikis et sur FR/EN son inclusion
dans getDefaultQueueTypes() ; si exclu ou durablement retardé, un timer
ciblé peut aussi être nécessaire pour cette édition.

Après ces contrôles et inspection des files, installer les deux modèles
en tant que root, sans toucher aux services existants :

```bash
cd /home/users/webmaster/www/farm.wikidebates.org/w

install -m 0644 ops/systemd/mw-interlanguage-purge@.service /etc/systemd/system/
install -m 0644 ops/systemd/mw-interlanguage-purge@.timer /etc/systemd/system/

systemctl daemon-reload

for wiki in de es it pt; do
	systemctl enable --now "mw-interlanguage-purge@$wiki.timer"
done

systemctl list-timers --all 'mw-interlanguage-purge@*.timer'
```

Observer l'exécution dans journalctl -u mw-interlanguage-purge@de.service.
Le budget de 30 s n'interrompt pas un job individuel en cours ; le
service possède un timeout systemd de cinq minutes. La cadence et la
taille des lots sont des choix opérationnels révisables. Ne pas installer
les timers avant que le job soit enregistré sur les wikis dépendants.
L'activation de InterlanguageExtensionNotifyFallbackChanges n'est pas
automatique : elle vient après la recette des API et des workers.

## Nettoyage historique

Procéder après activation du code qui ignore les auto-correspondances
centrales et protège les correspondances existantes. Refaire l'inventaire
sur le primaire, sauvegarder les lignes exactes hors du webroot, vérifier
le moteur transactionnel et comparer les données avec la sauvegarde
avant une suppression transactionnelle. Ne supprimer que les lignes
supplémentaires FR/FR ou dont la source n'existe plus. Ne pas supprimer
automatiquement les cibles manquantes ou les conflits. Ne pas écrire dans
langlinks : les liens natifs se corrigent dans le wikicode.

Le script `ops/interlanguage/cleanupMappings.php` vérifie le SHA-256,
l'identité de la base FR et l'égalité exacte entre les lignes candidates
actuelles et le JSON sauvegardé (clés et titres, pas seulement les compteurs).
Il refuse une sauvegarde modifiée, périmée ou issue d'une autre base.
En mode --apply, il vérifie aussi InnoDB pour les deux tables, verrouille les
lignes candidates et supprime seulement les clés sauvegardées ; le statut
orphelin est revérifié au moment du DELETE. Toute différence entraîne
l'annulation de la transaction. Le mode simulation est celui par défaut.

Conserver le JSON d'origine dans le répertoire privé des audits, vérifier
son empreinte connue avant utilisation et ne pas l'écraser. Exemple (renseigner le nom réel du fichier et son empreinte vérifiée) :

```bash
backup=/home/users/webmaster/files/interlanguage-audits/cleanup-backup.json
hash=EMPREINTE_SHA256_VERIFIEE

printf '%s  %s\n' "$hash" "$backup" | sha256sum -c -

php maintenance/run.php ./ops/interlanguage/cleanupMappings.php \
	--wiki=fr --backup="$backup" --sha256="$hash"
```

Après le déploiement des correctifs et une simulation réussie sur les
données encore présentes, répéter avec --apply. Ne pas lancer cette
opération pendant un import, une restauration ou une réorganisation
massive de pages ; éviter les écritures concurrentes sur ces relations.
Vérifier après succès dans une nouvelle connexion SQL que le nombre
de FR/FR et d'orphelins est nul. Le script ne déclenche ni réanalyse
ni purge de contenu : traiter les propriétés persistantes et les caches
dans la phase de recette finale.

## Audit local périodique

Depuis la racine MediaWiki de la ferme, avec l'utilisateur qui exécute
les maintenances ordinaires (pas root) :

```bash
bash tests/ci/runInterlanguageMappingAudit.sh
```


Le script lit les tables existantes via la maintenance eval de MediaWiki,
sans réseau public, parsing, purge, suppression ni modification de page.
Il utilise un verrou flock, un répertoire privé /home/users/webmaster/files/interlanguage-audits
et un rapport JSON horodaté accompagné de son journal. En cas d'échec,
il conserve un journal d'échec mais ne publie pas de rapport incomplet.
Les rapports sont conservés pour comparaison ; leur archivage ou
suppression reste une décision de l'administrateur.

Exemple hebdomadaire (lundi à 03 h 25, heure locale du serveur).
Choisir une heure sans conflit avec les maintenances existantes.
Vérifier manuellement le script avec le même PATH et le même utilisateur
avant d'ajouter cette ligne, une seule fois, au crontab existant :

```cron
25 3 * * 1 PATH=/usr/local/bin:/usr/bin:/bin /usr/bin/bash /home/users/webmaster/www/farm.wikidebates.org/w/tests/ci/runInterlanguageMappingAudit.sh
```


Vérifier l'emplacement du PHP CLI avec command -v php ; adapter le PATH
du cron si la ferme emploie un autre binaire. Le script ne change pas le
crontab lui-même. Le code de sortie zéro signifie que l'audit a abouti,
pas que les anomalies sont absentes. Comparer les rapports et examiner
les écarts ; ne pas transformer les observations en nettoyage automatique.
L'audit de base ne certifie pas le HTML public ni l'indexabilité des cibles.
Il compare les graphies brutes au petit ensemble de recherches exactes
réellement utilisé par le wiki destinataire. Les anomalies inhabituelles
se corrigent à la source, et non par un parcours coûteux pendant le rendu.
