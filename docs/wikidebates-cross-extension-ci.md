# CI transversale des extensions épinglées

La CI `.github/workflows/farm-cross-extension-checks.yml` s'exécute sur les PR vers la ferme qui changent les pointeurs de sous-modules, `.gitmodules`, `composer.lock`, ou le code Flow/PageForms. Elle n'effectue **aucune** mise à jour distante des versions Git : le script utilise exactement les SHA des gitlinks de la PR.

Le job télécharge uniquement les cinq sous-modules nécessaires (Wikidebates, VEForAll, VisualEditor, Echo et MinervaNeue), puis prépare une **installation jetable** MediaWiki 1.43 avec SQLite et SMW. Il charge ensemble Flow, Wikidebates, PageForms, VisualEditor et VEForAll, vérifie les modules ResourceLoader, puis exécute des assertions AutoParentLink et des tests de formulaires.

Le protocole ne modifie ni les sites FR/EN ni leurs caches. La présence de SMW dans une instance jetable sert à valider les dépendances du formulaire : ce job **ne traite pas le chantier de fraîcheur du cache SMW**, volontairement différé (Wikidebates Core #61).

Les tests existants de navigateur sont dans le workflow PageForms ; ils ne se substituent pas à un test sur les véritables formulaires et aux vérifications de production. Voir `docs/wikidebates-deployment-readonly-audit.md`.

**VEForAll** : la branche de suivi du sous-module est désormais `main` dans le fork `wikidebates-veforall`, créé au même commit que la branche stable auparavant référencée. Aucun gitlink n'a été déplacé. GitHub affiche encore la branche datée comme branche par défaut du fork tant que l'administrateur n'a pas changé ce paramètre.
