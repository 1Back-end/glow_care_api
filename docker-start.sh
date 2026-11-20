#!/usr/bin/env bash
set -e

# Port d'écoute d'Apache (Render fournit PORT)
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# S'assurer que storage et bootstrap/cache sont accessibles avant toute commande artisan
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Lien symbolique storage
php artisan storage:link --force || true

# Rafraîchir les caches de configuration et de routes avec les variables d'environnement Render
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache

# Remise à zéro volontaire, UNIQUEMENT si RESET_DB=true (à utiliser une seule fois)
if [ "$RESET_DB" = "true" ]; then
    php artisan migrate:fresh --force
else
    # Migrations normales : ajoute le nouveau, ne supprime rien
    php artisan migrate --force
fi

# Mise à jour des données de base (sans supprimer l'existant)
if [ "$RUN_SEED" = "true" ]; then
    php artisan db:seed --force
fi

# Synchronisation des permissions à chaque déploiement
php artisan permission:extract || echo "permission:extract a échoué, voir les logs ci-dessus"

# Réappliquer les droits après les éventuels fichiers créés par artisan
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

exec apache2-foreground
