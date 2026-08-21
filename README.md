# Fleetview — plugin GLPI

Plugin GLPI 11+ d'intégration de l'API **Masternaut** (Michelin Connected
Fleet), l'outil de géolocalisation des véhicules des techniciens.

Sur le formulaire de ticket, sous le champ d'attribution du technicien, un
bouton **« Techniciens à proximité »** s'affiche lorsque le lieu du ticket
dispose de coordonnées GPS. Il ouvre une modale avec une carte (Leaflet,
embarqué dans GLPI) centrée sur la localisation du demandeur et affichant la
position des véhicules de la flotte.

La bulle de chaque véhicule indique la distance à vol d'oiseau, le temps et
la distance par la route (OSRM), et le **planning à venir** du technicien
associé : tâches de tickets planifiées et événements externes (congés,
réservations…), triés chronologiquement, avec un badge « en cours »,
« aujourd'hui » ou « demain ». Les droits GLPI s'appliquent (tâches privées,
visibilité du planning, entités).

## Prérequis

- GLPI >= 11.0
- PHP >= 8.2
- Un accès à l'API Masternaut

## Installation (développement)

```bash
# 1. Cloner le dépôt (n'importe où, ou directement dans glpi/plugins/)
git clone https://github.com/JeremieMercier/fleetview.git /chemin/vers/fleetview

# 2. Si cloné hors de GLPI : lier le plugin dans le dossier plugins
ln -s /chemin/vers/fleetview /chemin/vers/glpi/plugins/fleetview

# 3. (Optionnel, pour les tests) installer l'autoloader
cd /chemin/vers/fleetview && composer install
```

Puis installer et activer le plugin depuis *Configuration → Plugins*, ou en
ligne de commande depuis le dossier GLPI :

```bash
php bin/console glpi:plugin:install fleetview -u glpi
php bin/console glpi:plugin:activate fleetview
```

> Note : les outils qualité et les tests supposent que le plugin est
> physiquement dans `glpi/plugins/` (chemins relatifs `../../`). Avec un lien
> symbolique, voir la section Qualité ci-dessous.

## Configuration

*Configuration → Générale → onglet Fleetview* : URL de base, numéro client
Connect, utilisateur Partner (HTTP Basic) et secret de l'API, rayon de
recherche, nombre de véhicules et durée du cache. Le secret est chiffré en
base via GLPIKey (hook `secured_configs`) et n'apparaît jamais dans le dépôt.

Onglet *Affichage* : couleurs des marqueurs, filtres, nombre d'entrées du
planning listées dans la bulle (`0` masque la section) et prise en compte des
événements externes (activée par défaut ; les événements récurrents ne sont
pas développés).

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
