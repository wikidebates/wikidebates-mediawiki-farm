Interlanguage centralisé pour MediaWiki 1.43

Cette version reprend la logique de votre variante "Pavel" :
- les liens interlangues peuvent être saisis sur un seul wiki central ;
- les autres wikis n'ont pas besoin de dupliquer les liens dans chaque langue.

Adaptations faites pour MediaWiki 1.43 :
- remplacement des accès directs à certaines propriétés internes par des getters ;
- remplacement de DB_MASTER par DB_PRIMARY ;
- branchement explicite du hook SkinTemplateOutputPageBeforeExec sur sa méthode ;
- conservation de la table interlanguage_links et du module API langlinks modifié.

Installation
1. Déposer ce dossier dans extensions/Interlanguage
2. Sur le wiki central : require_once "$IP/extensions/Interlanguage/InterlanguageCentral.php";
3. Sur les wikis dépendants : require_once "$IP/extensions/Interlanguage/Interlanguage.php";
4. Régler les variables globales comme dans votre installation actuelle
5. Sur le wiki central, lancer update.php pour créer la table interlanguage_links

Remarque
Cette extension est archivée côté Wikimedia et n'a pas de branche officielle REL1_43 maintenue. Ce paquet est donc un port pragmatique vers MediaWiki 1.43, pas une version officielle Wikimedia.

Correctifs de compatibilite et de securite (novembre 2026)
- Les connexions SQL vers le wiki central sont separees pour les lectures et les ecritures.
- Le module API personnalise utilise ConnectionProvider plutot que wfGetDB().
- Le mode API distant utilise JSON (formatversion=1) et le service HTTP de MediaWiki.
- Les donnees PHP serializees deja enregistrees restent lisibles sans instancier de classes.
- Les liens interlangues supplementaires sont ajoutes via OutputPage::addLanguageLinks().
- Les donnees destinees a OutputPage sont stockees avec setProperty()/getProperty().
- La fonction de parseur est enregistree sur chaque instance du parseur.
- Les reponses de purge distante anormales sont journalisees (canal Interlanguage).

Tests sans demarrer MediaWiki :
    php extensions/Interlanguage/tests/InterlanguageSmokeTest.php

Verifier la syntaxe :
    php -l extensions/Interlanguage/Interlanguage.php
    php -l extensions/Interlanguage/InterlanguageExtension.php
    php -l extensions/Interlanguage/InterlanguageCentralExtension.php
    php -l extensions/Interlanguage/InterlanguageCentralExtensionPurgeJob.php
    php -l extensions/Interlanguage/api/ApiQueryLangLinks.php

A valider avant deploiement : liens fr/en, ajout, modification et suppression
de liens sur le wiki central, purge distante et fonctionnement de l'API.
Aucune migration de base de donnees n'est necessaire pour ces correctifs.

Non modifie volontairement : logique de detection des differences dans
LinksUpdate, authentification/reessais des purges distantes, et deplacement
des ecritures SQL hors du rendu. Ces changements ont un impact fonctionnel
et necessitent des tests d'integration specifiques.
