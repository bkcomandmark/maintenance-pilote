# Maintenance Pilote

Extension WordPress de maintenance et de publication pilotée par Claude (BK COM AND MARK).

## Code source

Le code est dans le dossier `maintenance-pilote/`. Le fichier `maintenance-pilote.zip` joint à chaque release est ce dossier compressé (Finder : clic droit sur le dossier > Compresser), avec le dossier `maintenance-pilote/` à la racine de l'archive.

## Publier une nouvelle version

1. Onglet **Releases** > **Draft a new release**.
2. **Choose a tag** : saisir le numéro de version précédé de `v` (ex. `v2.4.0`), puis **Create new tag**.
3. **Release title** : le même numéro.
4. Description : les nouveautés, en quelques lignes (elles s'affichent dans WordPress).
5. Glisser le fichier **`maintenance-pilote.zip`** dans la zone des fichiers joints. Le nom du fichier doit être exactement celui-ci.
6. **Publish release**.

Chaque site vérifie GitHub toutes les 12 heures et installe la nouvelle version automatiquement.
Pour accélérer : Réglages > Maintenance Pilote > « Vérifier maintenant », puis Tableau de bord > Mises à jour.

Règle : installer d'abord la nouvelle version à la main sur un seul site, la tester, puis publier la release.
