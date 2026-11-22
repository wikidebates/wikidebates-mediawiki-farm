# Vérification publique FR et EN — lecture seule

L'outil `tests/ci/publicSiteVersionAudit.py` effectue **exactement deux requêtes HTTPS GET** vers `fr.wikidebates.org` et `en.wikidebates.org`, sur `/w/api.php?action=query&meta=siteinfo&siprop=general%7Cextensions&format=json`.

Il affiche uniquement la version MediaWiki annoncée (`generator`), le nom du wiki et la liste des **extensions publiquement déclarées**. Il produit `wikidebates-public-site-audit.json` et un résumé dans GitHub Actions. La CI de la PR utilise `--strict` pour signaler immédiatement un domaine inaccessible ou une réponse non conforme ; le workflow est aussi lançable manuellement.

Ce n'est **pas** une inspection du serveur : le SHA Git réellement exécuté par PHP-FPM, la branche de déploiement, les sous-modules privés, `LocalSettings.php`, les autres configurations, workers et cron ne sont **pas observables** par ce contrôle public. Les extensions absentes de la liste `siteinfo` ne sont pas nécessairement non chargées ; elles peuvent ne pas être déclarées publiquement.

Compléter impérativement par `bash maintenance/inspectFarmDeployment.sh` sur chaque serveur et par les vérifications humaines de `docs/wikidebates-deployment-readonly-audit.md`. **Ne jamais appliquer automatiquement un changement en fonction de ce rapport**.

Exclusion explicite : aucune lecture, mesure ou modification des caches SMW ni aucune purge, en cohérence avec le report de `wikidebates-core#61`.
