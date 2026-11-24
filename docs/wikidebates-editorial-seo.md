# SEO éditorial : inventaire et application

## Relevé sans modification

```sh
python3 tests/ci/publicSeoAudit.py --all-homepages --editorial-inventory
```

Le nouvel indicateur est facultatif. Il lit une requête API fixe par édition,
avec les propriétés SEO et le début (2400 caractères maximum) et, si tronqué, la fin (1600 caractères maximum) du wikicode
de la dernière révision de quelques pages publiques institutionnelles.
Aucune recherche de titres libre, aucun suivi des liens, aucun auteur de
révision demandé, aucun appel d'édition/purge. Le statut de réponse,
l'identifiant de révision, les redirections et les sources indisponibles
sont distingués des contenus réellement observés. Un modèle absent est
un résultat possible, non une erreur. Les propriétés sont brutes et
peuvent être sérialisées par WikiSEO ; le wikicode peut être tronqué.

Les six accueils sont également observés en HTTP via le contrôle existant.
Les URL d'images externes sont inventoriées, pas automatiquement suivies.
Ne pas conclure à l'accessibilité de Googlebot sur la seule base d'un HEAD.
En mode combiné, le budget du script principal est au plus 60 requêtes HTTP
avec les redirections (six index, six accueils, six HEAD, six inventaires
de namespaces, six inventaires éditoriaux), sans crawl ni cron.
L'observation en CI est informative ; les tests hors réseau sont bloquants.

## Politique éditoriale

Utiliser les modèles proposés dans WikiSEO seulement après vérification
de leur contenu et de la version installée. Réutiliser shortdesc si sa
valeur est pertinente ; ne pas saisir une deuxième description sans
raison. Vérifier les sources et les propriétés avant de proposer un
changement, et ne pas ajouter une deuxième invocation de #seo.

| Page | Type recommandé | Description |
| --- | --- | --- |
| Accueil FR/EN et autres éditions | WebPage, WebSite via la configuration | Description courte dans la langue du wiki, à conserver si adaptée |
| À propos / About | AboutPage | Projet, architecture des arguments et pensée critique |
| Laboratoire du débat méthodique / Laboratory of Methodical Debate | AboutPage | Association, recherche et diffusion de méthodes de débat |
| Principes fondateurs / Founding Principles | WebPage | Règles de neutralité, lisibilité, vérifiabilité et contribution |
| Portail:Débats | CollectionPage | Parcours des débats et arguments |
| Portail:Contributions | CollectionPage | Navigation dans les pages à améliorer, utilité éditoriale à distinguer de l'indexabilité |
| Contribuer / Contribute | WebPage | Comment participer |
| Aide:Créer un débat / Help:Creating a debate | WebPage | Trouver un sujet, recueillir et structurer les arguments |
| Aide:Utiliser les pages de discussion | WebPage | Échanges entre contributeurs et règles de discussion |
| Foire aux questions / Frequently Asked Questions | WebPage | Questions sur le fonctionnement du projet, sans promesse de résultat enrichi FAQ |

Les pages d'association décrivent l'organisation, mais restent des
AboutPage. Le nœud Organization est configuré une seule fois par identité
dans WikiSEO. Les accueils français et anglais ainsi que les pages de
présentation sont publiquement consultables ; les autres éditions doivent
être vérifiées individuellement. Ne pas rédiger de descriptions de contenus
non observés. Ne pas présumer de l'indexabilité de pages techniques.

Sur une page de présentation avec une bonne description courte et sans
autre appel WikiSEO, l'utilisation du modèle peut se limiter à :

```wikitext
{{SEO projet
	|schema=AboutPage
}}
```

La variante anglaise est `{{SEO project|schema=AboutPage}}`.
Une page WebPage sans besoin de métadonnées spécifiques n'a pas besoin de
modèle SEO. Ne pas ajouter de dates, auteurs ou images inventés. Les
modifications éditoriales et de configuration restent à appliquer au wiki,
pas aux fichiers du moteur MediaWiki. Aucun changement de cache dans ce lot.

## Relevé du 24 novembre 2026 : modèles historiques et images

L'inventaire de la PR #185 a observé HTTP 200 pour les six accueils et les
six appels API. Le FR possède seulement shortdesc sur son accueil ; le ES
publie une expression #if non interprétée dans la description. Les modèles
SEO projet et SEO project sont absents ; les pages utilisent déjà
Modèle:Métabalises et Template:Meta tags. Les inclure dans le relevé avant
de décider de leur évolution. Conserver les métadonnées manuelles spécifiques
et ne pas installer des modèles concurrents sans besoin établi.

```sh
python3 tests/ci/publicSeoAudit.py --all-homepages --editorial-inventory --shared-assets
```

L'option shared-assets ajoute exactement trois GET de PNG (les URL sociale
partagée et française observées, ainsi qu'une candidate française suivie
par Git) et deux GET de robots.txt, sur les deux hôtes explicitement
autorisés. Au plus un redirect vers une autre URL de la même liste et du
même hôte. Les dimensions sont lues dans IHDR, pas par décodage complet.
La taille, le type MIME, X-Robots-Tag et les groupes pertinents de robots.txt
sont rapportés sans interpréter complètement les règles d'un moteur.
Aucun suivi automatique d'une URL externe trouvée dans une page.
Ces dix requêtes maximales supplémentaires portent le budget du script
principal à 70 requêtes avec les autres options et redirections. Les
observations publiques restent informatives et les tests simulés bloquants.
