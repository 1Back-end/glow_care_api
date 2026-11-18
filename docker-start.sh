#!/usr/bin/env bash

# Port d'écoute d'Apache (Render fournit PORT)
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf

# Cache de configuration
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache

# Migrations
php artisan migrate --force

# Seeder, puis extraction des permissions (seulement si RUN_SEED=true)
if [ "$RUN_SEED" = "true" ]; then
    php artisan db:seed --force
    php artisan permission:extract || echo "permission:extract a échoué, voir les logs ci-dessus"
fi

# Permissions des dossiers APRÈS toutes les commandes artisan
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

exec apache2-foreground
