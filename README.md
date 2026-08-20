# Fleetview — plugin GLPI

Plugin GLPI 11+ d'intégration de l'API **Masternaut** (Michelin Connected
Fleet), l'outil de géolocalisation des véhicules des techniciens.

Sur le formulaire de ticket, sous le champ d'attribution du technicien, un
bouton **« Techniciens à proximité »** s'affiche lorsque le lieu du ticket
dispose de coordonnées GPS. Il ouvre une modale avec une carte (Leaflet,
embarqué dans GLPI) centrée sur la localisation du demandeur et affichant la
position des véhicules de la flotte.

## Prérequis

- GLPI >= 11.0
- PHP >= 8.2
- Un accès à l'API Masternaut

## Installation (développement)

```bash
ln -s /Users/jeremie/Herd/fleetview /chemin/vers/glpi/plugins/fleetview
```

Puis installer/activer le plugin depuis *Configuration → Plugins*.

## Configuration

*Configuration → Générale → onglet Fleetview* : URL de base, numéro client
Connect, utilisateur Partner (HTTP Basic) et secret de l'API, rayon de
recherche, nombre de véhicules et durée du cache. Le secret est chiffré en
base via GLPIKey (hook `secured_configs`) et n'apparaît jamais dans le dépôt.

L'API « Live Position Latest » est limitée à 1 requête / 15 s : les positions
sont mises en cache côté serveur (cache GLPI, durée configurable, minimum
15 s).

La documentation PDF de l'API se place dans `docs/api/` (dossier ignoré par
git).

## Structure

- `setup.php` / `hook.php` — déclaration et cycle de vie du plugin
- `src/PluginConfig.php` — configuration (onglet + stockage `glpi_configs`)
- `src/Controller/MapController.php` — endpoints AJAX (contexte ticket, positions véhicules)
- `src/Masternaut/MasternautClient.php` — client API MCF Connect (Find Nearest Vehicle + Live Position Latest, cache)
- `js/fleetview.js` — bouton, modale et carte Leaflet
- `templates/config.html.twig` — formulaire de configuration

## Contributing

* Open a ticket for each bug/feature so it can be discussed
* Follow [development guidelines](http://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
* Refer to [GitFlow](http://git-flow.readthedocs.io/) process for branching
* Work on a new branch on your own fork
* Open a PR that will be reviewed by a developer

## Qualité (outils GLPI)

Le plugin passe les outils qualité du core GLPI sans erreur :

- `php-cs-fixer` (standard PER-CS) ;
- `phpstan` niveau **max** avec les extensions GLPI (`phpstan-glpi`, règles de
  dépréciation, fonctions `Safe`) ;
- `rector` avec la baseline officielle `PluginsRector.php` du core ;
- `twigcs`, `eslint` (config GLPI) et `stylelint`.

Note : les configurations du template (`phpstan.neon`, `rector.php`,
`eslint.config.mjs`, `.stylelintrc.js`) référencent GLPI via `../../` et
supposent donc que le plugin est **physiquement** dans `glpi/plugins/`. Si le
plugin est lié par lien symbolique (setup de dev), lancer les outils avec des
configurations recopiées en chemins absolus.
