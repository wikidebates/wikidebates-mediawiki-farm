# Validation cumulative avant le déploiement SEO

Le workflow `seo-release-benchmark.yml` réutilise les tests déjà présents
dans les extensions Wikidebates et WikiSEO, avec les gitlinks exacts de
la ferme et le coeur MediaWiki 1.43.12 de cette même révision. PHP 8.4,
Composer de la ferme, bases SQLite jetables. Aucun LocalSettings.php
existant, aucune donnée de production, aucun serveur externe sollicité
par les benchmarks. Installation de dépendances et lecture Git nécessaires.

## Contrôles bloquants

- Coexistence des métadonnées natives et WikiSEO, vraies révisions FR/EN.
- Dates de publication, opérations d'historique et ParserCache persistant.
- Graphes structurés et listes de relations.
- Invalidation ciblée des vrais stores de fichiers Vector et Minerva.
- Parser #seo et <seo>, canonical, WebSite/Organization, robots.
- ShortDescription FR/EN, hreflang des six éditions et absence de SQL
  additionnel lors de l'assemblage de ces derniers.

Le test des stores HTML ne constitue pas un test HTTP des skins privés
de production. Le workflow Interlanguage distinct couvre déjà le parcours
HTTP sur six bases MariaDB et Minerva upstream ; il ne doit pas être
remplacé par ce laboratoire SQLite. Les résultats de production, SMW,
Cloudflare et latences concurrentes sont vérifiés séparément.

## Comparaison avant/après

Deux couples d'extensions sur le même runner et le même coeur :
référence historique des gitlinks de la ferme f0e2bf8a7fd3 (Wikidebates
21d0c0d27ac7, WikiSEO ca976844f6a8), puis gitlinks du candidat. La référence
n'est pas une affirmation sur l'état actuel du serveur. Le banc existant
combinedBenchmark.php est copié du candidat pour garantir les mêmes
fixtures, instrumentation et assertions dans chaque processus. Les deux
tours inversent l'ordre des versions ; 36 processus indépendants.

Les temps CPU et wall, pics incrémentaux de mémoire PHP, SQL observés
et empreintes sont rapportés. Les sorties doivent être stables dans
chaque version. Les différences entre versions sont à examiner avant
déploiement, non rejetées automatiquement : les nouvelles dates,
descriptions et sémantiques ont modifié intentionnellement certaines
sorties. Pas de seuil de temps fragile en CI. Le cas native-hreflang
historique n'active pas les routes dynamiques ; les tests dédiés
hreflang.php les contrôlent réellement dans les six langues.

Les mesures des hooks et du parser ne sont pas des mesures HTTP ni une
somme des gains historiques. Une réponse servie depuis un cache HTML
ne repasse pas par ces hooks.

## Mesures historiques vérifiées le 24 novembre 2026

- [WikiSEO, benchmark officiel/modernisé/optimisé](https://github.com/wikidebates/WikiSEO/actions/runs/45388300714) : réduction CPU de 20,2 à 28,4 % pour les pages ordinaires, 36,5 à 36,8 % pour les pages natives, 5,9 % pour le cas parser ; MediaWiki 1.43.11. SQL stable ou réduit dans ces fixtures.
- [Wikidebates, date native](https://github.com/wikidebates/wikidebates-core/actions/runs/45386047541) : recherche indexée MariaDB de 91 à 157 µs pour 1 à 2 000 révisions, 1 SELECT par lecture, index rev_page_timestamp.
- [Wikidebates, refonte Schema.org](https://github.com/wikidebates/wikidebates-core/actions/runs/45406182061) : médiane débat 12,877 à 13,167 ms, argument 6,847 à 7,105 ms, SELECT respectivement 2 et 1 avant/après, avec 10 relations de chaque côté. Référence a849b4afb68d (dates natives déjà présentes). Taille JSON-LD débat 4328 à 5606 octets, argument 2856 à 5823 octets.
- [Wikidebates, hooks et stores](https://github.com/wikidebates/wikidebates-core/actions/runs/45406182050) : tests réels de coexistence et invalidation ciblée réussis, avec le précédent gitlink WikiSEO ca976844f6a8.

Ne pas attribuer ces résultats historiques à l'intégration exacte des
derniers gitlinks sans consulter le nouveau workflow. Les tests
fonctionnels de déploiement et la recette publique restent nécessaires.
