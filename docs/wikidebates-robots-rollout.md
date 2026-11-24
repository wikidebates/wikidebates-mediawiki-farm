# Modernisation de robots.txt — préparation du déploiement

La politique de référence est [docs/seo/robots-policy.txt](seo/robots-policy.txt).
Elle n'est pas directement servie en production. Le document root, la
configuration Apache/KeyHelp et un éventuel ajout Cloudflare doivent être
vérifiés avant tout remplacement.

## Choix conservateurs

- Conserver tous les jetons d'agents bloqués dans les robots.txt FR et EN
  relevés le 24 novembre 2026, y compris les anciens outils de collecte.
  Regrouper les interdictions, sans modifier la politique vis-à-vis des IA.
- Les deux anciennes exceptions intégralement permissives IsraBot et
  Orthogaffe, héritées de Wikipédia, sont retirées : ces agents suivent
  désormais les règles communes, qui autorisent toujours les articles.
- Conserver les exclusions de routes dynamiques, les exceptions historiques
  mobileview et REST doc, ainsi que le délai de 10 secondes pour les agents
  qui le respectent. Google ignore ce délai ; Bing peut l'appliquer.
- Ne plus bloquer les fichiers statiques CSS, JS, polices et images sous
  /w/skins/. Les règles sont limitées aux extensions listées dans le fichier,
  avec ou sans paramètres ; les autres chemins de /w/skins/ restent bloqués.
  Il s'agit de règles d'exploration, pas de règles d'accès au serveur.
- Bloquer les préfixes des pages spéciales localisés des six wikis. Retirer
  les règles RandomPage et Search redondantes avec /wiki/Special:.
- Ne modifier ni noindex, ni Cloudflare, ni CrawlerBurstProtection, ni le
  contenu des sitemaps, ni les chemins publics.

## Générer le fichier sans installer

Depuis la racine de la ferme :

```sh
python3 tests/ci/robotsPolicy.py --render shared
python3 tests/ci/robotsPolicy.py --render fr
python3 tests/ci/robotsPolicy.py --audit
```

La sortie shared contient les six liens Sitemap existants, adaptée à un
robots.txt physiquement commun. Les variantes par langue ne contiennent
que l'index de leur hôte. Les règles d'exploration sont identiques. La
présence de plusieurs sitemaps n'est pas une erreur en soi : ne pas créer
six nouveaux fichiers de production si le partage actuel est volontaire.

L'audit lit exactement six fichiers publics, sans redirection ni
authentification, au maximum 500 Kio et 12 secondes par réponse. Il compare
les groupes et règles, signale toute interdiction intégrale perdue et
toute directive inconnue ajoutée par l'infrastructure. Il ne simule pas
toute la sémantique de Google, ne teste pas les ressources HTTP et ne
prouve pas l'indexation. Les tests hors réseau sont des tests de contrat
sur les chemins et agents concernés.

Avant installation, examiner les différences sur les six hôtes ; conserver
toute règle de production supplémentaire justifiée. Identifier le vrai
fichier ou la règle qui sert /robots.txt. Sauvegarder le fichier hors du
document root, préserver ses droits, remplacer uniquement ce fichier,
puis vérifier HTTP 200, text/plain, les règles et les sitemaps. En cas
d'écart, restaurer le fichier précédent. Aucun changement automatique
du serveur, aucun cron, aucune purge globale.

## Références

- RFC 9309 : https://www.rfc-editor.org/rfc/rfc9309.html
- Google : https://developers.google.com/crawling/docs/robots-txt/robots-txt-spec
- Google : https://developers.google.com/search/docs/crawling-indexing/robots/intro
- Bing : https://www.bing.com/webmasters/help/crawl-error-alerts-e29a3f3e

Le fichier robots.txt n'est ni une protection d'accès ni un moyen fiable
de désindexer des pages ; les directives noindex doivent rester lisibles.
