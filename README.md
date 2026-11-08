# mcm-prix-amazon

Plugin WordPress : prix Amazon actualisés via l'API Creators (shortcode `[prix asin="..."]`), avec repli automatique vers un lien simple si le prix n'est plus à jour.

- Code du plugin : `mcm-prix-amazon/`
- Mode d'emploi : `mcm-prix-amazon/LISEZ-MOI.txt`
- Paquets publiés : `dist/` (le plugin installé les récupère via `update.json`)
- Tests : `PLUGIN_FILE=$PWD/mcm-prix-amazon/mcm-prix-amazon.php php tests/harness.php`

Aucun identifiant n'est stocké dans ce dépôt : ils se saisissent dans Réglages > Prix Amazon de chaque site (ou dans `wp-config.php`).

## Publier une version
1. Modifier le code, changer `Version:` (en-tête) et `const VERSION`, ajouter une entrée dans `CHANGELOG.md`.
2. Lancer les tests, puis `./build.sh`.
3. `git add -A && git commit && git push` : les sites installent la version d'eux-mêmes (vérification toutes les 12 h environ, au plus tard).
