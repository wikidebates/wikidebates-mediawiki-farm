# Audit régulier des dépendances Git et Composer

L'inventaire du 22 novembre 2026 est suivi dans [l'issue #78](https://github.com/wikidebates/wikidebates-mediawiki-farm/issues/78) : 53 sous-modules, dont 44 externes (42 références différentes du sommet de leur branche, 2 identiques). Les neuf forks Wikidebates étaient épinglés sur leur branche suivie via le connecteur GitHub autorisé.

Le workflow `pinned-dependencies-audit.yml` produit un rapport **en lecture seule** pour les 53 gitlinks (SHA épinglé, branche suivie et SHA amont) et le workflow distinct `composer-security-audit.yml` exécute `composer audit --locked --abandoned=report` sur les versions PHP réellement verrouillées. Les résultats sont disponibles dans le résumé du job et l'artifact JSON, sans écrire de commit, déployer, purger ou changer un sous-module.

**Limites :** les branches Gerrit sont vérifiées via les miroirs Wikimedia GitHub ; ces miroirs peuvent avoir du retard. Le jeton Actions de la ferme ne peut pas accéder au fork privé MinervaNeue sans autorisation inter-dépôts. Une divergence de SHA n'est ni une preuve de faille ni une obligation de mise à jour. Les alertes de sécurité font échouer la CI. Les paquets seulement **abandonnés** sont signalés mais ne font pas échouer le job, afin de les migrer séparément ; voir l'issue #81. Toute mise à jour passe par une PR et des tests sous MediaWiki 1.43.

La branche par défaut est désormais `main` (vérifiée le 24 novembre 2026). Les audits sont quotidiens : gitlinks à 04:17 UTC, Composer à 04:37 UTC.

**Hors champ :** aucune modification des caches SMW ou de leurs options. La fraîcheur sémantique reste différée : [Wikidebates Core #61](https://github.com/wikidebates/wikidebates-core/issues/61).

## Déclencheurs lors des mises à jour de sous-modules

Depuis la PR #93, l'audit se déclenche lors de **toute modification de gitlink**, notamment `extensions/Wikidebates`, `extensions/VEForAll`, les forks `skins/*` et le gitlink imbriqué `extensions/CrawlerProtection/build`. Le filtre GitHub `extensions/*` vise un chemin de premier niveau (un gitlink), **pas** tous les fichiers PHP sous `extensions/Flow/**`, ce qui évite de lancer cet audit à chaque modification d'une extension incluse dans la ferme.

Le rapport quotidien continue de fonctionner sur `main`. Les changements ne mettent jamais automatiquement à jour de sous-modules, et n'activent aucun mécanisme de fraîcheur SMW.


## Triage amont quotidien

Le script complémentaire auditUpstreamChanges.py classe les comparaisons fast-forward : current, maintenance (traductions/outillage), runtime (code ou configuration à examiner), security (marqueur explicite de sécurité avec changements potentiellement exécutés), review (divergence, comparaison incomplète ou ancre de fork à revoir) et unavailable (accès impossible). Les comparaisons limitées par l'API ne sont pas considérées comme sûres. Un correctif de sécurité non identifié par les marqueurs peut rester classé runtime. Les fichiers de développement peuvent eux aussi avoir des vulnérabilités ; le triage ne remplace pas les audits de sécurité.

Le rapport inclut les tags stables MediaWiki 1.43.x et les nouvelles versions stables de la même majeure des cinq extensions Composer explicitement suivies. Ce sont des candidats, pas des versions déclarées compatibles ; les nouvelles majeures ne sont pas suivies par ce contrôle, et les vulnérabilités Composer restent traitées par l'audit dédié.

Les six ancres Wikimedia de forks personnalisés ont été relevées dans les parents de leurs commits intégrés, puis vérifiées dans les dépôts amont. Si un gitlink de fork change, revoir son ancre dans tests/ci/upstreamForkBaselines.json : ne pas faire passer une ancienne ancre pour la base du nouveau fork. HitCounters suit historiquement master ; aucun changement automatique de branche. Le fork privé MinervaNeue peut rester inaccessible au jeton de la ferme, mais sa référence amont publique est contrôlée. VEForAll, CrawlerBurstProtection, WikiSEO et Wikidebates sont surveillés sur leurs branches déclarées ; le rapprochement avec un éventuel amont externe n'est pas encore établi. Les miroirs Wikimedia restent consultatifs.

Les résultats complets sont dans upstream-audit.json, joint à l'artifact existant, et dans le résumé du job. Un commentaire consolidé est publié dans l'issue #78 seulement pour de nouvelles empreintes de candidats (code, sécurité, version ou vérification manuelle), sur exécution planifiée ou manuelle de main. Les traductions seules, les erreurs réseau et les candidats déjà signalés ne génèrent pas de nouveaux commentaires. Les erreurs d'accès sont visibles dans le rapport ; elles ne constituent pas une preuve de sécurité. Le job de notification seul reçoit issues:write ; les PR ne publient pas de commentaire. Aucune mise à jour automatique, aucun déploiement ni changement de cache. Les workflows existants sur PR et push restent actifs.
