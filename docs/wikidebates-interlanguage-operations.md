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

## Nettoyage historique

Procéder après activation du code qui ignore les auto-correspondances
centrales et protège les correspondances existantes. Refaire l'inventaire
sur le primaire, sauvegarder les lignes exactes hors du webroot, vérifier
le moteur transactionnel et comparer les données avec la sauvegarde
avant une suppression transactionnelle. Ne supprimer que les lignes
supplémentaires FR/FR ou dont la source n'existe plus. Ne pas supprimer
automatiquement les cibles manquantes ou les conflits. Ne pas écrire dans
langlinks : les liens natifs se corrigent dans le wikicode.

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
