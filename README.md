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
associé : tâches de tickets planifiées et événements externes du planning,
triés chronologiquement, avec un badge « en cours », « aujourd'hui » ou
« demain ». L'itinéraire routier des trois véhicules les plus proches est
tracé sur la carte, dans la couleur de leur marqueur. Les droits GLPI
s'appliquent : tâches privées, visibilité du planning et des tickets, et
entités des associations véhicule / technicien.

## Prérequis

- GLPI >= 11.0
- PHP >= 8.2
- Un accès à l'API Masternaut

## Installation (production)

En production, le plugin s'installe depuis la **marketplace GLPI**
(*Configuration → Plugins → Marketplace*, bouton *Installer* puis
*Activer*) ; les mises à jour y sont ensuite proposées directement.

### Installation manuelle

Sans passer par la marketplace, chaque version est disponible sous forme
d'archive sur la page
[Releases](https://github.com/JeremieMercier/fleetview/releases). Elle
s'installe dans le dossier `marketplace/` de GLPI (le dossier `plugins/`
reste réservé au développement).

```bash
# Depuis le dossier marketplace de GLPI, en remplaçant 0.5.0 par la version voulue
cd /chemin/vers/glpi/marketplace

# 1. Télécharger et extraire l'archive (elle contient le dossier fleetview/)
curl -LO https://github.com/JeremieMercier/fleetview/releases/download/0.5.0/glpi-fleetview-0.5.0.tar.bz2
tar -xjf glpi-fleetview-0.5.0.tar.bz2 && rm glpi-fleetview-0.5.0.tar.bz2

# 2. Donner les fichiers à l'utilisateur du serveur web (www-data, apache, nginx…)
chown -R www-data:www-data fleetview

# 3. Installer puis activer le plugin, en ligne de commande…
php ../bin/console plugin:install fleetview -u glpi
php ../bin/console plugin:activate fleetview
```

… ou depuis l'interface GLPI : *Configuration → Plugins*, boutons
*Installer* puis *Activer* sur la ligne Fleetview. Renseigner ensuite la
configuration (voir ci-dessous).

> Le dossier doit s'appeler exactement `marketplace/fleetview`. Si une copie
> du plugin existe aussi dans `plugins/fleetview`, la supprimer : GLPI n'en
> charge qu'une.

### Mise à jour manuelle

Pour un plugin installé manuellement, la mise à jour suit le même principe.
La configuration (accès API, options d'affichage, associations
véhicule / technicien) est stockée en base et conservée lors de la mise à
jour. Faire tout de même une sauvegarde de la base avant.

```bash
cd /chemin/vers/glpi/marketplace

# 1. Remplacer les fichiers par ceux de la nouvelle version
curl -LO https://github.com/JeremieMercier/fleetview/releases/download/0.5.0/glpi-fleetview-0.5.0.tar.bz2
rm -rf fleetview
tar -xjf glpi-fleetview-0.5.0.tar.bz2 && rm glpi-fleetview-0.5.0.tar.bz2
chown -R www-data:www-data fleetview

# 2. Appliquer la mise à jour (le plugin passe en « À mettre à jour ») et le
#    réactiver, en ligne de commande…
php ../bin/console plugin:install fleetview -u glpi
php ../bin/console plugin:activate fleetview

# 3. Vider le cache GLPI (fichiers JS et traductions versionnés)
php ../bin/console cache:clear
```

… ou depuis l'interface GLPI : *Configuration → Plugins*, boutons *Mettre à
jour* puis *Activer* sur la ligne Fleetview. Si GLPI indique en ligne de
commande que le plugin est déjà installé, ajouter `--force` à
`plugin:install`.

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
recherche (valeur par défaut du sélecteur de la carte) et rayon maximal
(borne haute de ce sélecteur, 150 km par défaut, 500 km au plus), nombre de
véhicules et durée du cache. Le secret est chiffré en
base via GLPIKey (hook `secured_configs`) et n'apparaît jamais dans le dépôt.

Le service de routage (temps de trajet, itinéraires) est désactivé par
défaut : voir la section dédiée ci-dessous avant de renseigner son URL.

Onglet *Affichage* : couleurs des marqueurs, filtres, titre de la bulle (nom
du technicien GLPI associé ou nom du véhicule Masternaut), affichage de la
plaque d'immatriculation, tracé des itinéraires, nombre d'entrées du planning
listées dans la bulle (`0` masque la section), prise en compte des
événements externes (activée par défaut ; les événements récurrents ne sont
pas développés) et affichage des véhicules sans technicien associé.

Onglet *Associations véhicules / techniciens* : chaque véhicule de la flotte
est associé à un utilisateur GLPI, dans une entité, avec ou sans ses
sous-entités (par défaut l'entité active, sous-entités comprises). Voir la
section Droits pour la portée de ces associations. Le tableau est paginé
selon la préférence GLPI « nombre d'éléments par page », filtres et tri
portant sur toute la flotte ; les associations s'enregistrent page par page,
et les suggestions par nom ne s'appliquent qu'en cliquant dessus.

La carte propose un interrupteur « Véhicules sans technicien » : désactivé
(valeur par défaut configurable), seuls les véhicules associés à un
technicien GLPI (association explicite ou correspondance par nom) sont
affichés ; le filtre s'applique avant le classement des plus proches et la
limite du nombre de véhicules.

### Droits

La carte expose des données de flotte (positions GPS en temps réel, noms des
conducteurs) : elle est réservée aux profils disposant du droit *Carte des
techniciens à proximité* (onglet *Fleetview* de la fiche Profil, droit
`plugin_fleetview_map`). Sans ce droit, le bouton n'apparaît pas et les
routes du plugin répondent 403, quel que soit le droit de lecture sur le
ticket. À l'installation, le droit est accordé aux profils autorisés à
attribuer des tickets, à d'autres ou à eux-mêmes : avec les profils GLPI
d'origine, technicien, hotliner, superviseur, admin, super-admin et
observateur (ce dernier peut s'attribuer un ticket) ; les profils
self-service et lecture seule ne l'ont pas. Vérifiez cette attribution
après l'installation et retirez le droit aux profils qui n'ont pas à voir
la flotte, par exemple observateur s'il est réservé à des managers ou des
auditeurs ; les personnalisations sont conservées à la mise à jour. Un
profil créé après l'installation n'a pas le droit. L'attribution depuis la
carte requiert en plus le droit d'attribuer les tickets, et n'accepte que
les techniciens que la carte propose pour ce ticket : associés à un
véhicule (association explicite ou correspondance par nom) et habilités à
prendre des tickets dans l'entité du ticket, comme la liste déroulante
native des acteurs.

**Entités.** La flotte Masternaut est globale (un seul compte), la carte
affiche donc les véhicules autour du ticket quelle que soit l'entité. Ce
qui est propre à GLPI, l'identité du technicien qui conduit chaque véhicule,
est en revanche limité à l'entité du ticket, comme la liste déroulante
native des acteurs : une association n'est prise en compte que pour les
tickets de son entité (et de ses sous-entités si l'option est cochée), et la
correspondance par nom ne considère que les utilisateurs ayant un profil
couvrant l'entité du ticket. Sur les autres tickets, le véhicule apparaît
comme « sans technicien », sans nom et sans bouton d'attribution ; par
défaut, ces véhicules sont masqués. Les associations existantes sont rattachées à
l'entité racine avec sous-entités lors de la mise à jour vers 0.6.0, ce qui
conserve le comportement précédent : restreindre ensuite chaque association
à une entité est un choix de l'administrateur.

Le planning listé dans la bulle (tâches planifiées et événements externes)
suit le droit GLPI *Planning* du profil (onglet *Assistance*) : « voir tous
les plannings » affiche celui de tous les techniciens, « voir le planning de
mon groupe » uniquement celui des membres des groupes de l'utilisateur, « voir
mon planning » le sien seulement. Quand l'utilisateur n'a pas le droit de
consulter le planning du technicien associé, la section est simplement masquée
dans la bulle.

L'API « Live Position Latest » est limitée à 1 requête / 15 s : les positions
sont mises en cache côté serveur (cache GLPI, durée configurable, minimum
15 s).

La documentation PDF de l'API se place dans `docs/api/` (dossier ignoré par
git).

### Service de routage (OSRM)

Les temps de trajet et les itinéraires sont calculés par un serveur
[OSRM](https://project-osrm.org) dont l'URL est configurable (onglet
Fleetview). Les coordonnées GPS du lieu du ticket et des véhicules affichés
sont envoyées à ce serveur à chaque ouverture de la carte : des coordonnées
seules, sans nom ni identifiant, accompagnées de l'adresse IP du serveur
GLPI.

**Le routage est désactivé par défaut** (URL vide) : la carte fonctionne
sans, les bulles indiquent alors la distance à vol d'oiseau et les véhicules
sont triés selon elle. L'activer est une décision explicite de
l'administrateur ; quand l'URL renseignée pointe hors du réseau de
l'organisation, le formulaire affiche un avertissement sur ce transfert à
un tiers.

Le serveur de démonstration du projet, `https://router.project-osrm.org`,
peut être renseigné pour un essai. Ses contraintes :

- **Serveur de démonstration, sans garantie de service** : le projet OSRM le
  réserve aux usages modérés et peut bloquer les usages intensifs. Le
  rate-limit n'est pas publié (ordre de grandeur constaté : ~1 requête/s par
  IP, au-delà les requêtes sont refusées).
- Limites du moteur (valeurs par défaut d'`osrm-routed`) : **100 coordonnées
  par requête `table`**, 500 par requête `route`. Le plugin envoie le lieu du
  ticket + un point par véhicule affiché : au-delà de 99 véhicules affichés,
  la requête `table` est refusée et les temps de trajet ne sont plus
  disponibles.

Volume généré par le plugin : à chaque ouverture de la carte hors cache,
**1 requête `table`** (temps et distances de tous les véhicules) **+ 1 requête
`route` par itinéraire tracé** (3 par défaut, envoyées en parallèle). Les
réponses sont mises en cache 5 minutes par jeu de coordonnées. Pour un usage
courant (quelques agents, quelques dizaines d'ouvertures par jour), le serveur
de démonstration suffit. En cas d'erreurs intermittentes (itinéraires
absents, `429` dans `files/_log/fleetview.log`), désactiver le tracé des
itinéraires ou passer sur un serveur dédié.

Tout échec du routage est dégradé silencieusement : la carte et les
marqueurs restent affichés, seuls les temps de trajet et les itinéraires
manquent (tri à vol d'oiseau).

**Auto-hébergement** (recommandé) : pour un usage soutenu ou pour ne pas
envoyer de coordonnées à un tiers, OSRM s'installe avec l'image Docker
`osrm/osrm-backend` et un extrait OpenStreetMap de la zone couverte (par
exemple depuis [Geofabrik](https://download.geofabrik.de/)) :

```bash
# Préparation (une fois ; ~4 Go de RAM pour la France entière)
docker run -t -v "$PWD:/data" osrm/osrm-backend osrm-extract -p /opt/car.lua /data/france-latest.osm.pbf
docker run -t -v "$PWD:/data" osrm/osrm-backend osrm-partition /data/france-latest.osrm
docker run -t -v "$PWD:/data" osrm/osrm-backend osrm-customize /data/france-latest.osrm

# Service
docker run -d -p 5000:5000 -v "$PWD:/data" osrm/osrm-backend osrm-routed --algorithm mld /data/france-latest.osrm
```

Renseigner ensuite `http://<hôte>:5000` comme URL du service de routage. Les
limites (`--max-table-size`, `--max-viaroute-size`) deviennent paramétrables
et il n'y a plus de rate-limit.

## Structure

- `setup.php` / `hook.php` — déclaration et cycle de vie du plugin
- `src/PluginConfig.php` — configuration (onglet + stockage `glpi_configs`)
- `src/Profile.php` — droit *Carte des techniciens à proximité* (onglet de la fiche Profil)
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
