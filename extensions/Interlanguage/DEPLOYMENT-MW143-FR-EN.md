# Mise en service controlee d'Interlanguage Central (MediaWiki 1.43 / PHP 8.4)

## Etat de reference, 23 novembre 2026

- FR : \`LocalSettings.php\` inclut \`extensions/Interlanguage/InterlanguageCentral.php\`
  (chargement historique). Le manifeste \`Interlanguage\` n'est PAS charge,
  et le job \`purgeDependentWikis\` est inactif.
- EN : conserver le chargement historique \`Interlanguage.php\`, l'URL de
  l'API centrale, son prefixe interwiki et la base centrale.
- Le champ \`iw_api\` de l'interwiki \`en\` a ete renseigne sur FR avec
  \`https://en.wikidebates.org/w/api.php\`; l'API repond et un POST de purge
  ponctuel a retourne \`purged\` sans erreur ni avertissement.
- NE PAS considerer les caches HTML fichier ou Cloudflare comme valides par
  cette seule observation : leurs invalidations doivent etre testees a part.

## Preconditions avant activation

1. Les correctifs de \`LinksUpdate\` (PR #114) et du controle de purge
   JSON (PR #111) ont ete fusionnes dans \`main\`, testes ensemble et
   deployes sur le serveur. La PR #110 (redirections sur EN) est
   independante. La PR #113 a ete fermee sans fusion.
2. Aucune modification de configuration sur EN. Sur FR, ne pas charger a
   la fois \`InterlanguageCentral.php\` et le manifeste.
3. Faire une sauvegarde de \`LocalSettings.php\` hors du repertoire public,
   avant toute edition. Conserver la possibilite de retablir la seule
   instruction \`require_once\` de FR sans revenir sur le champ \`iw_api\`.
4. Choisir une page de test existante sur FR et sa correspondance EN.
   Ne pas modifier une page publique uniquement pour verifier une purge.
5. Verifier que le wiki FR peut lire la table centrale
   \`interlanguage_links\` : le hook \`LanguageLinks\` y fera des lectures.

## Audit en lecture seule avant activation

Depuis la racine de la ferme :

\`\`\`bash
cd /home/users/webmaster/www/farm.wikidebates.org/w || exit 1
php -l LocalSettings.php || exit 1
php maintenance/run.php eval --wiki=fr <<'PHP'
$s = \MediaWiki\MediaWikiServices::getInstance();
$db = $s->getConnectionProvider()->getReplicaDatabase();
echo 'Table interlanguage_links : ' . ($db->tableExists('interlanguage_links', 'InterlanguageDeployAudit') ? 'PRESENTE' : 'ABSENTE') . "\n";
$iw = $s->getInterwikiLookup()->fetch('en');
echo 'API EN : ' . ($iw && $iw->getAPI() === 'https://en.wikidebates.org/w/api.php' ? 'CORRECTE' : 'INCORRECTE') . "\n";
$jobTable = $db->tableExists('job', 'InterlanguageDeployAudit');
echo 'Table job SQL : ' . ($jobTable ? 'PRESENTE' : 'ABSENTE') . "\n";
echo 'Jobs Interlanguage deja en SQL : ' . ($jobTable ? (int)$db->selectField('job', 'COUNT(*)', ['job_cmd' => 'purgeDependentWikis'], 'InterlanguageDeployAudit') : 'NON VERIFIABLE PAR SQL') . "\n";
echo 'Configuration de file : ' . ($GLOBALS['wgJobTypeConf']['purgeDependentWikis']['class'] ?? $GLOBALS['wgJobTypeConf']['default']['class'] ?? 'INCONNUE') . "\n";
PHP
\`\`\`

Le comptage SQL ne couvre QUE les jobs stockes dans la table \`job\`.
Si la file utilise un autre backend (Redis, etc.), ne pas en deduire qu'il
n'existe aucun job en attente. Si des jobs anciens existent, examiner
leur provenance et leur contenu en lecture seule AVANT de les executer :
le code de \`LinksUpdate\` precedemment actif pouvait produire des
destinations de langue numeriques. Ne pas vider la file aveuglement.

## Activation limitee a FR

Une fois le code valide et deploye, remplacer UNIQUEMENT dans la branche
\`$wikiId === 'fr'\` de \`LocalSettings.php\` :

\`\`\`php
require_once( $IP . '/extensions/Interlanguage/InterlanguageCentral.php' );
\`\`\`

par :

\`\`\`php
wfLoadExtension( 'Interlanguage' );
\`\`\`

La branche \`else\` d'EN reste INCHANGEE.

## Validation de l'enregistrement apres activation

\`\`\`bash
cd /home/users/webmaster/www/farm.wikidebates.org/w || exit 1
php -l LocalSettings.php || exit 1
php maintenance/run.php eval --wiki=fr <<'PHP'
$s = \MediaWiki\MediaWikiServices::getInstance();
echo 'Manifeste central : ' . (\ExtensionRegistry::getInstance()->isLoaded('Interlanguage') ? 'CHARGE' : 'ABSENT') . "\n";
$h = $s->getHookContainer();
echo 'LinksUpdate : ' . ($h->isRegistered('LinksUpdate') ? 'PRESENT' : 'ABSENT') . "\n";
echo 'LanguageLinks : ' . ($h->isRegistered('LanguageLinks') ? 'PRESENT' : 'ABSENT') . "\n";
$q = $s->getJobQueueGroup();
echo 'Job central : ' . (in_array('purgeDependentWikis', $q->getQueueTypes(), true) ? 'PRESENT' : 'ABSENT') . "\n";
echo 'File par defaut : ' . (in_array('purgeDependentWikis', $q->getDefaultQueueTypes(), true) ? 'INCLUSE' : 'EXCLUE') . "\n";
echo 'Jobs en attente : ' . $q->get('purgeDependentWikis')->getSize() . "\n";
PHP
\`\`\`

Verifier que FR et EN affichent leurs pages et liens interlangues normalement,
sans nouveau message d'erreur PHP ou avertissement anormal. Si le job est
exclu de la file par defaut, identifier le traitement planifie qui l'execute.

## Recette fonctionnelle, une seule modification

1. Relever les liens FR/EN visibles et les caches de la page de test.
2. Modifier UN seul lien interlangue sur une page de test FR, vers une
   destination EN existante. Ne pas declencher \`runJobs\` sans limitation.
3. Verifier qu'un seul job \`purgeDependentWikis\` est produit, avec
   uniquement \`en\` comme destination et le(s) titre(s) attendu(s).
4. Executer AU PLUS le job cible apres inspection ou attendre le mecanisme
   normal de file ; ne pas rejouer d'anciens jobs suspects.
5. Verifier la reponse API (\`formatversion=2\`, \`purged:true\`), les logs
   \`Interlanguage\`, et les liens HTML de FR et EN pour les visiteurs
   anonymes. Tester separement les caches fichier et CDN.
6. Verifier aussi la suppression d'un lien et le cas d'un lien inchange,
   sur une instance de test avant d'utiliser ces operations en production.

Ne pas conclure que la purge est automatique simplement parce qu'un POST
manuel est reussi ou parce que le type de job est enregistre.

## Repli, sans purge globale

Restaurer sur FR l'ancien \`require_once( .../InterlanguageCentral.php )\`,
verifier \`php -l LocalSettings.php\` et que le manifeste n'est plus charge.
Ne pas supprimer ou vider les files de jobs sans diagnostic, et ne pas
annuler l'URL API interwiki EN deja correctement configuree.

Les PR et les tests simules ne remplacent pas la recette de production.
