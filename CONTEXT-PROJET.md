# Carnet de spots — API (Laravel)

> Document de référence pour Claude Code sur ce repo. Ce repo est la **source de vérité** du projet — il sert l'API consommée par `carnet-spots-web` et `carnet-spots-mobile`, ainsi que les fichiers `.pmtiles` pour le offline carto.

## 1. Contexte & besoin du projet

App personnelle (usage solo en V1, pour les tests) pour marquer des points GPS précis (parkings, spots de nuit, emplacements repérés) même sans adresse existante (ex. en pleine forêt), avec une note libre, pour les retrouver et les consulter une fois sur le terrain **sans réseau**.

À moyen terme, l'app a vocation à s'ouvrir à d'autres utilisateurs (multi-comptes) — c'est la raison pour laquelle `owner_id` est présent dès la V1 sur `points` et `tags`, avec un scoping strict par owner (jamais de partage entre utilisateurs au sein de l'app).

Deux phases d'usage :

- **Préparation** (maison, wifi stable) : recherche de spots, ajout de points via le web.
- **Terrain** (fourgon, souvent sans réseau) : consultation/mise à jour via mobile, sans dépendance réseau — cette API n'est donc contactée par le mobile qu'en amont (sync avant départ) et en aval (rejeu des mutations au retour du réseau), jamais en continu.

## 2. Rôle de ce repo dans l'architecture globale

**3 repos séparés**, cette API comme unique source de vérité :

- `carnet-spots-api` (ce repo, Laravel) — API REST JSON pure
- `carnet-spots-web` (React + Vite) — client HTTP de cette API, usage préparation
- `carnet-spots-mobile` (React Native CLI bare, Android) — client HTTP de cette API (sync ponctuelle) + stockage local SQLite pour le offline terrain

## 3. Décisions techniques actées pour ce repo

| Sujet          | Décision                                                                                                                                   | Raison                                                            |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------- |
| Style API      | REST JSON pur, **pas d'Inertia**                                                                                                           | Évite la duplication de logique entre client web et mobile        |
| Auth           | **Laravel Sanctum** (token)                                                                                                                | Écosystème Laravel natif, suffisant pour usage perso/multi-device |
| Recherche géo  | **Haversine SQL** + index composite `(lat, lng)`                                                                                           | Suffisant pour le volume perso, pas besoin de PostGIS             |
| Tuiles offline | Cette API sert aussi les fichiers **`.pmtiles`** régionaux (Protomaps) en fichiers statiques, téléchargés par le mobile avant un road trip | Voir section 5                                                    |

## 4. Schéma de données (migrations)

### `points`

```php
Schema::create('points', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->constrained('users');
    $table->string('title')->nullable();
    $table->decimal('latitude', 10, 7);
    $table->decimal('longitude', 10, 7);
    $table->text('note')->nullable();
    $table->enum('status', ['to_test', 'validated', 'to_avoid', 'already_slept'])
          ->default('to_test');
    $table->dateTime('visited_at')->nullable(); // datetime, not timestamp: any year (see migration 2026_10_26)
    $table->enum('visibility', ['private', 'public'])->default('private');
    $table->timestamps();

    $table->index(['latitude', 'longitude']);
});
```

### `tags`

```php
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->constrained('users');
    $table->string('name');
    $table->timestamps();

    $table->unique(['owner_id', 'name']); // évite les doublons de tags par utilisateur
});
```

### `point_tag` (pivot)

```php
Schema::create('point_tag', function (Blueprint $table) {
    $table->foreignId('point_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
    $table->primary(['point_id', 'tag_id']);
});
```

### `photos` (post-prototype, pas urgent)

```php
Schema::create('photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('point_id')->constrained()->cascadeOnDelete();
    $table->string('path');
    $table->timestamps();
});
```

L'action "marquer un point comme visité" (choix du status + note libre) est prévue dès la V1, en **texte seulement** : c'est un `PATCH` standard sur `/points/{id}` (status, visited_at, note), pas d'endpoint dédié. L'ajout d'images à cette action est confirmé au planning mais reporté après le prototype (nécessite la table `photos` ci-dessus + gestion d'upload/stockage).

**Partage** : aucune visibilité publique côté API (pas de route publique, pas de modération à prévoir en V1 malgré le champ `visibility`). Le seul partage envisagé passe par des canaux externes (WhatsApp et autres messageries) — modalités non figées, à définir plus tard (probablement export/génération de lien côté client, pas un endpoint public de cette API).

**Pourquoi `owner_id` et `visibility` dès la V1** (même en usage solo) : permet d'ouvrir l'app à d'autres utilisateurs plus tard sans migration destructive — juste des ajouts (policies Laravel, modération sur points publics, rate limiting).

## 5. Rôle de l'API dans le offline (tuiles + données)

L'app doit fonctionner **sans réseau sur le terrain**. Cette API n'intervient que hors du terrain :

### 5.1 Tuiles cartographiques — Protomaps / `.pmtiles`

- Le tile server officiel OSM interdit le téléchargement en masse/automatisé pour usage offline. OSM reste la source des données ; seule l'infrastructure de distribution change.
- Un fichier **`.pmtiles`** régional est généré (extraction ciblée sur la zone du road trip, pas la planète entière) et **servi en fichier statique par cette API**.
- Le mobile télécharge ce fichier avant le départ (au wifi) puis le lit en local via `file://` — l'API n'est plus sollicitée pour la carte une fois sur le terrain.
- Attribution ODbL (`© OpenStreetMap contributors`) gérée côté client (styles MapLibre).

### 5.2 Données applicatives — sync + rejeu de mutations

- **Sync descendante** : endpoint(s) permettant au mobile de récupérer les points pertinents (zone du road trip) avant le départ.
- **Rejeu de mutations** : le mobile accumule les changements offline (statut, note) dans une file locale ; cette API doit exposer les endpoints nécessaires pour les rejouer dès que le réseau est retrouvé (probable : endpoints CRUD standards sur `points`, avec gestion d'horodatage pour trancher les conflits — stratégie _last-write-wins_ envisagée vu l'usage solo, à documenter précisément avant implémentation).

## 6. Roadmap V1 pour ce repo

1. Migrations + modèles Eloquent (`Point`, `Tag`, `User`)
2. Routes API : CRUD `points`, CRUD `tags`, endpoint "points proches d'une position" (Haversine)
3. Auth Sanctum (même en solo, un seul compte)
4. Endpoint(s) de sync pour le mobile (récupération groupée par zone) + endpoints de rejeu de mutations
5. Service de fichiers statiques pour les `.pmtiles` régionaux

## 7. Hors scope V1

- Inscription multi-utilisateurs, policies d'accès croisé, modération des points publics
- Landing page / blog (sera un 4ᵉ repo Next.js séparé si le besoin se confirme, indépendant de cette API mais consommateur)
- Photos attachées aux points
- Notifications ("point à proximité")
