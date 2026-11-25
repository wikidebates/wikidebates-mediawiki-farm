# Suppression des fichiers de rendu invalidés après changement d'époque

Le renouvellement de CacheEpoch n'introduit aucun répertoire d'époque :
les fichiers valides et invalidés cohabitent dans les répertoires actifs.
Les trois caches occupent un espace disque privé. Ne pas retirer leurs
répertoires ni les verrous pour récupérer de la place.

Depuis la ferme, avec webmaster :

```sh
nice -n 15 python3 ops/cache/pruneRetiredRenderCaches.py --epoch=20261124224657
nice -n 15 python3 ops/cache/pruneRetiredRenderCaches.py --epoch=20261124224657 --apply
```

L'époque est un exemple : utiliser la valeur effectivement configurée.
Le programme vérifie les chemins privés et l'époque des six éditions
avant toute suppression. Il ne prend pas de liste de chemins arbitraires.
Il ne touche pas la base, les files d'éditions, les checkpoints, les
journaux ou les fichiers de génération. Il ne requiert aucune requête HTTP.

- Vector : seuls les noms de payload HTML natifs et dont le mtime est
  strictement antérieur à CacheEpoch sont candidats ; le verrou de
  fichier non bloquant et la comparaison d'identité réduisent les
  courses avec les écritures natives. Une publication atomique concurrente
  peut encore occasionner un cache miss supplémentaire, pas une perte
  de contenu. Ne pas lancer de reconstruction Vector forcée en parallèle.
- Minerva/Lecture : lire les JSON gzip bornés sous le verrou page.lock
  existant ; seul coreEpoch strictement antérieur à l'époque active est
  supprimé. Les fichiers verrouillés, illisibles, de format inconnu ou
  d'époque égale/postérieure sont conservés, pas interprétés comme
  obsolètes. Un TTL zéro reste inchangé.
- Aucun dossier, fichier de verrou, génération ou autre extension
  de fichier n'est supprimé. Les dossiers historiquement abandonnés
  doivent être identifiés séparément avant suppression.

Le mode sans --apply est une observation et ne supprime aucun fichier.
Les compteurs candidats, supprimés, inconnus et occupés portent sur
des observations pendant un site en ligne ; des fichiers peuvent être
renouvelés entre la prévisualisation et le passage effectif.
Les fixtures locales testent les limites d'époque, les verrous et
la conservation des fichiers actuels. Vérifier les configurations
effectives en production avant d'appliquer.
