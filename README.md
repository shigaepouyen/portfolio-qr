![Statut](https://img.shields.io/badge/statut-WIP-orange)

# Portfolio QR

Un portfolio photo sobre, pensé pour être ouvert depuis un QR code imprimé dans un book physique.
Le visiteur scanne et voit les photos, sans compte ni application. La photographe gère tout depuis son téléphone.

L'adresse du QR ne change jamais : le contenu peut être entièrement renouvelé sans réimprimer.

- **Côté visiteur** : séries de photos, visionneuse plein écran avec swipe, légendes, chargement progressif, design éditorial clair.
- **Côté gestion** : ajout de photos en lot depuis le téléphone, tri par glisser-déposer, légendes, couverture, déplacement entre séries, séries masquées, aperçu, QR code en SVG et PNG.
- **Technique** : PHP 8.1+ avec GD (WebP) et EXIF. Pas de base de données, pas de dépendance à installer, pas d'étape de build. Tourne sur n'importe quel hébergement mutualisé Apache.

## Installation

1. Copier le contenu du dépôt sur l'hébergement, par exemple dans un sous-dossier `portfolio/`.
2. Vérifier que PHP est en 8.1 ou plus, avec les extensions GD (support WebP) et EXIF.
3. S'assurer que `data/` et `media/` sont inscriptibles par PHP.
4. Ouvrir immédiatement `https://example.com/portfolio/admin`, indiquer le nom à afficher et **créer le mot de passe**.
   Tant qu'il n'existe pas, la première personne qui ouvre cette page peut le définir.
5. Dans « Adresse & QR », choisir si besoin une adresse lisible, puis télécharger le QR code en SVG.
6. Scanner le QR imprimé avant de l'intégrer au book.

Réglages PHP conseillés : `upload_max_filesize` 20M, `post_max_size` 25M, `memory_limit` 512M.
Les photos sont déjà réduites par le navigateur avant envoi (3000 px, JPEG), ces valeurs sont donc larges.

Si l'adresse publique détectée est fausse (proxy, sous-domaine particulier), copier `app/config.sample.php` en `app/config.php` et renseigner `public_base_url`.

## Personnalisation

Tout ce qui est propre à une personne se règle dans l'interface de gestion, rien n'est écrit dans le code :

| Réglage | Où |
|---|---|
| Nom affiché en tête du portfolio (ex. `MIKEY`) | Premier lancement, puis « Adresse & QR » |
| Sous-titre (par défaut `Portfolio`, peut être vidé) | « Adresse & QR » |
| Adresse lisible (ex. `/mikey-walsh`) | « Adresse & QR » |
| Séries, photos, légendes, couvertures | « Séries » |

Pour l'apparence : couleurs et typographie dans `assets/site.css` (variables en tête de fichier). La police Jost est auto-hébergée dans `assets/fonts/`.

## Adresses

- **Adresse secrète** (ex. `/k7Qm2xR9vT`) : générée au premier lancement, impossible à deviner, ne change jamais.
- **Adresse lisible** (ex. `/mikey-walsh`) : facultative, plus facile à deviner. Si elle est modifiée, l'ancienne reste active. Une ancienne adresse ne cesse de fonctionner que si on la désactive explicitement.
- Le QR encode l'une ou l'autre, au choix.

## Confidentialité et sécurité

- `X-Robots-Tag: noindex` sur toutes les pages et images, et pas de `robots.txt` qui révélerait le chemin.
- La racine du dossier et toute adresse inconnue renvoient une page 404 neutre.
- Photos ré-encodées en WebP : métadonnées EXIF (GPS, appareil, date) supprimées, orientation appliquée.
- `data/` et `app/` inaccessibles depuis le web, aucun script exécutable dans `media/`.
- Mot de passe haché, blocage de 15 minutes après 5 essais ratés, connexion mémorisée 90 jours. Changer le mot de passe déconnecte tous les appareils.
- Protection CSRF par en-tête personnalisé et cookie `SameSite=Lax`.
- Limite assumée : un visiteur peut toujours enregistrer ou capturer une photo affichée.

## Données et sauvegarde

- `data/portfolio.json` : tout le contenu (séries, ordre, légendes, adresses). Les 20 versions précédentes sont gardées dans `data/history/`.
- `media/` : trois tailles WebP par photo (640, 1280 et 2560 px sur le plus grand côté).
- Sauvegarder = copier `data/` et `media/`. Restaurer = remettre un fichier de `data/history/` à la place de `portfolio.json` (les photos supprimées entre-temps ne reviennent pas).

Ces deux dossiers sont exclus du dépôt : chaque installation a son propre contenu.

## Structure

```
index.php            routeur
app/bootstrap.php    stockage JSON, traitement d'images, authentification
app/public.php       pages visiteur
app/admin_api.php    API de gestion
app/admin_page.php   coquille de l'interface de gestion
assets/              CSS, JS, police, bibliothèques (PhotoSwipe, SortableJS, qrcode-generator)
data/  media/        créés et remplis à l'usage
```

## Développement local

```
php -S 127.0.0.1:8080 index.php
```

Puis ouvrir `http://127.0.0.1:8080/admin`.

## Pistes d'évolution

- Choix d'un thème sombre pour la partie visiteur.
- Export et import d'une archive complète (`data/` + `media/`) depuis l'interface.
- Annulation de la dernière modification à partir de `data/history/`.

## Licences des bibliothèques incluses

[PhotoSwipe](https://github.com/dimsemenov/PhotoSwipe) 5.4 (MIT), [SortableJS](https://github.com/SortableJS/Sortable) 1.15 (MIT), [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) 2.0 (MIT), police [Jost](https://github.com/indestructible-type/Jost) (OFL 1.1).
