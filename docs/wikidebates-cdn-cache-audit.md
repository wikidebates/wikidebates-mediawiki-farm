# Vérification en lecture seule des réponses HTML devant PHP

**Statut : diagnostic uniquement.** Ce contrôle ne prouve jamais que la configuration
Cloudflare ni celle du serveur web est sûre. Il complète l'issue #106.

## Sonde GitHub Actions

`tests/ci/publicCdnHeaderAudit.py` effectue **au plus six requêtes HTTPS GET**
anonymes : pour chaque wiki, une API publique `siteinfo` pour récupérer la
vraie page d'accueil et son `articlepath`, puis une vue de cette page avec
un User-Agent bureau et une vue avec User-Agent mobile. Il n'accède ni à un
compte ni à une page non publique, ne lance pas de purge et n'écrit rien en
production. Les corps HTML sont ignorés et aucun cookie, token ou identifiant
Cloudflare n'est publié dans le rapport.

L'audit conserve seulement le statut HTTP et des en-têtes explicitement
autorisés dans `wikidebates-public-cdn-headers.json`. Le workflow est déclenché
à la demande ou par les modifications de ces fichiers, sans planification.
Il ne dépend pas de la configuration secrète du serveur.

- `edge-reuse-observed` (HIT, STALE, UPDATING, REVALIDATED) : une réponse HTML
	a été réutilisée côté Edge. Les règles applicables et l'invalidation des
	pages restreintes doivent être vérifiées avant toute automatisation.
- `eligible-or-revalidated-later` (MISS, EXPIRED) : rien ne prouve qu'une
	seconde visite ne ferait pas HIT.
- `not-reused-observed` (DYNAMIC, BYPASS) : ce GET n'a pas réutilisé le
	cache Cloudflare ; un autre URL, un Worker ou une règle différente peut faire HIT.
- `unverified` : aucun verdict possible, notamment si la connexion échoue,
	si un challenge est rencontré ou si le statut n'est pas disponible.

**Important :** une réponse `Cache-Control: private, no-store` peut malgré
tout être conservée par une règle Edge TTL qui ignore l'origine. Les réponses
observées ne constituent pas un inventaire de ces règles.

## Audit indispensable des configurations réelles

Pour `fr.wikidebates.org` et `en.wikidebates.org`, relever dans Cloudflare
les **Cache Rules**, anciennes Page Rules, Workers et éventuels `cache.put()`,
Transform Rules, TTL des navigateurs et variantes de clés de cache.
Vérifier explicitement `/wiki/*`, `/w/index.php`, `/w/api.php` et
`action=wikidebatesreading`. Ne pas appliquer d'option `Cache Everything`
ou d'Edge TTL ignorant les contrôles de l'origine au HTML de MediaWiki.
En cas de doute, imposer un **bypass** pour le HTML soumis aux permissions.

Sur le serveur, vérifier en lecture seule les `document_root`, `alias`,
`try_files`, `X-Accel-Redirect`, `fastcgi_cache`, `proxy_cache` et
Varnish éventuel. Le cache natif Vector utilise `FileCacheDirectory` ;
le code MediaWiki n'empêche pas la lecture directe par le serveur web de
ces fichiers. Le `cache/.htaccess` du dépôt contient une interdiction
Apache mais **n'est pas une preuve de protection sous nginx**. Interdire tout
accès direct au cache Vector, Minerva et Lecture ; privilégier des répertoires
privés hors racine web. Ne pas confondre cette règle avec la possibilité
d'accéder à `index.php`, qui vérifie les autorisations PHP.

Ne pas publier les chemins privés ou `LocalSettings.php` dans les issues.
Ne modifier aucun réglage du serveur ou de Cloudflare automatiquement à
partir de la sonde. Aucun préchauffage nocturne ne doit être réactivé avant
une vérification de l'ensemble des couches, en particulier des changements
de droits anonymes et de RevisionDelete sur un wiki **jetable**.

Documentation Cloudflare :
- https://developers.cloudflare.com/cache/concepts/default-cache-behavior/
- https://developers.cloudflare.com/cache/how-to/cache-rules/settings/
- https://developers.cloudflare.com/cache/concepts/cache-responses/
