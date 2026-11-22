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
2. Sur le wiki central FR : wfLoadExtension( 'Interlanguage' );
   Ancien require_once( $IP . '/extensions/Interlanguage/InterlanguageCentral.php' ) :
   mode historique de repli, qui ne charge pas le manifeste et n'enregistre
   ni LinksUpdate central ni purgeDependentWikis. Ne pas charger les deux.
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

Tests sans demarrer MediaWiki (sans reseau ni ecriture SQL) :
	php extensions/Interlanguage/tests/InterlanguageSmokeTest.php
	php extensions/Interlanguage/tests/InterlanguageApiSmokeTest.php
	php extensions/Interlanguage/tests/InterlanguageDatabaseSmokeTest.php
	php extensions/Interlanguage/tests/InterlanguageParserSmokeTest.php
	php extensions/Interlanguage/tests/InterlanguageCentralSmokeTest.php
	php extensions/Interlanguage/tests/InterlanguagePurgeSmokeTest.php

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

Recette fonctionnelle recommandee avant toute fusion
- Sur une instance de test fr/en, comparer les liens interlangues existants
  avant/apres activation des correctifs (pages ordinaires et redirects).
- Verifier les pages ayant une relation explicite avec le wiki central,
  les titres comportant un deux-points et les pages sans traduction.
- Verifier le module API "prop=langlinks" sur les deux wikis.
- Verifier qu'un second parseur construit dans la meme requete reconnait
  la fonction interlanguage.
- Sur une instance de test seulement, creer/modifier/supprimer un lien
  interlangue et confirmer que les pages ciblees sont actualisees.
- Surveiller les avertissements PHP et le canal de logs Interlanguage.
- Les tests de code autonomes simulent les dependances MediaWiki :
  leur reussite ne demontre pas le bon fonctionnement interwikis reel.

Verification non destructive de l'API avant/apres deploiement
- Enregistrer la sortie de la meme requete avant et apres la mise a jour.
- Pour le wiki francais :
	curl -fsSG 'https://fr.wikidebates.org/w/api.php' \
		--data-urlencode 'action=query' \
		--data-urlencode 'prop=langlinks' \
		--data-urlencode 'titles=Dieu existe-t-il ?' \
		--data-urlencode 'format=json' \
		--data-urlencode 'formatversion=1'
- Pour le wiki anglais, utiliser l'URL en.wikidebates.org et un titre
  existant dont les liens interlangues sont connus.
- Les requetes ci-dessus sont des lectures ; elles ne purgent rien et ne
  modifient pas les pages. Les tests de changement de liens se font
  uniquement sur une installation de test distincte.

Nettoyage du depot (22 novembre 2026)
- La sauvegarde historique `InterlanguageExtension (Copie).php` a ete retiree du repertoire actif : elle definissait la meme classe que `InterlanguageExtension.php` mais utilisait des API et une deserialisation obsoletes. Le fichier demeure recuperable dans l'historique Git.
- La verification de syntaxe CI parcourt desormais tous les fichiers PHP presents, sans exception pour la copie supprimee.
- Aucun changement a la pagination de `ApiQueryLangLinks`, aux ecritures pendant le rendu ou aux purges distantes ; ces comportements demandent des essais fonctionnels FR/EN avant modification.

Correction MW 1.43 (23 novembre 2026) : LinksUpdate
- getLanguageLinks() retourne une liste de chaines "lang:page", sans cle
  de langue ; le hook central historique construisait un tableau numerique
  et risquait d'envoyer des jobs aux prefixes 0, 1, etc.
- Le hook normalise maintenant en langue => titre => true, comme le
  traitement natif LangLinksTable (premier titre prioritaire), conserve les
  deux cibles d'un renommage et ne purge pas une page sans ParserOutput.
- Test sans reseau, SQL reel ou ecriture :
	php extensions/Interlanguage/tests/InterlanguageCentralJobHookSmokeTest.php
- Sur une production ou le manifeste vient d'etre active : revenir au
  chargement historique jusqu'a validation et deploiement de ce correctif.

Validation de la chaine de purge (simulation hors production)
- Le test InterlanguageCentralPurgePipelineSmokeTest.php utilise les classes
  reelles du hook central et du job mais simule la base, la file et l'HTTP.
  Il verifie : pas de job pour un lien inchange ; un job pour une nouvelle
  cible ; purge des titres EN anciens et nouveaux ; aucune requete sur une
  autre destination ; aucune erreur API lorsque la purge est acceptee.
	php extensions/Interlanguage/tests/InterlanguageCentralPurgePipelineSmokeTest.php
- Ce test ne valide pas les jobs, la base et les caches de production.
  Activer le manifeste uniquement apres integration des correctifs, puis
  faire une recette FR/EN sur une modification d'une page de test.
- LocalSettings sur le wiki central FR, une fois le code deploye :
	wfLoadExtension( 'Interlanguage' );
  Sur EN : conserver require_once( $IP . '/extensions/Interlanguage/Interlanguage.php' );
  Ne pas charger les deux points d'entree sur FR simultanement.

Diagnostic et purge distante API : novembre 2026
- L'entree interwiki en sur FR contient desormais une URL API correcte,
  mais la purge automatique requiert le chargement du manifeste central.
- Le job demande formatversion=2 afin de lire correctement purged:true.
  Le format JSON 1 retourne une chaine vide pour les indicateurs vrais ;
  ne jamais comparer directement cette chaine au booleen true.
- Sur une requete multi-pages, toutes les pages doivent confirmer la purge ;
  sinon un avertissement agrege est emis (sans titres ni secrets).
- Les destinations interwiki manquantes ou sans API sont journalisees sans
  ajouter de tentatives automatiques, et aucun droit n'est elargi.
- Procedure pre-deploiement, audit des jobs, recette et rollback :
  DEPLOYMENT-MW143-FR-EN.md. PR #115 reunit les correctifs #111 et #114.
