# Inventaire local des anciens SEO dans les fichiers HTML

Après les essais ciblés réussis en production, ne pas reprendre
aveuglément la reconstruction Vector commencée avant le déploiement.
Un rendu Vector récent peut contenir un ancien ParserOutput. Minerva
peut servir un ancien fichier sans expiration.

`ops/seo/auditLegacySeoCaches.php` observe les fichiers du namespace
principal FR/EN à partir des pages courantes non redirigées du primaire.
Utilise le chemin et la validation temporelle de HTMLFileCache pour
Vector (view uniquement) ; pour Minerva, vérifie la génération et
l'identité (révision, touched, epochs, format), avec lectures bornées.
Les variantes non courantes ne sont pas candidates. Une génération qui
change pendant le relevé rend la page non vérifiée.

```sh
php maintenance/run.php ./ops/seo/auditLegacySeoCaches.php \
	--wiki=fr --limit=2000 --max-seconds=300 --max-load=6
```

En cas d'arrêt limit/time/load, reprendre avec --after-id=next_after.
Un relevé complet du parcours ne signifie pas que toutes les variantes
ont été observées : 64 variantes maximum par page, fichiers illisibles,
courses et sorties inconnues signalés. Les compteurs Vector portent sur
les pages, les compteurs Minerva sur les variantes (et incidents).
Les indices positifs sont QAPage, Claim sans LearningResource, et URL
sociale anglaise historique défectueuse dans les métabalises. Ils sont
des **candidats à examiner**, pas une preuve universelle d'obsolescence.
Le marqueur courant WebPage/LearningResource ne prouve pas toute la
conformité SEO ; l'absence d'indice ne certifie pas les ParserOutputs
sans fichier, ni hreflang, ni les pages ordinaires hors périmètre.

Aucun parsing de wikicode, HTTP, modification SQL, purge, écriture de
cache ou checkpoint, ni modification des paramètres de cache. Le
rapport ne contient ni corps HTML ni en-têtes. Conserver les JSON dans
un répertoire privé hors webroot. Avant toute purge, revalider les
identifiants, titres, révisions et indices en production ; le relevé
n'est pas une autorisation automatique de purge. Les outils existants
restent responsables de la reconstruction, et les checkpoints existants
sont préservés. Tests hors réseau et PHP 8.4 sur fixtures ; le parcours
MediaWiki et les chemins effectifs restent à vérifier sur un petit lot.
