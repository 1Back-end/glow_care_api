#!/usr/bin/env bash

# Adapter le port d'écoute d'Apache selon la variable PORT (utilisée par Render)
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf

# S'assurer que les permissions des dossiers de logs et de cache sont correctes
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Nettoyer et mettre en cache la configuration Laravel
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache

# Exécuter les migrations et les seeders automatiquement en production
php artisan migrate --seed --force

# Lancer le serveur web Apache au premier plan
exec apache2-foreground
