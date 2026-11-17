#!/bin/sh

# Adapter le port d'écoute d'Apache selon la variable PORT (utilisée par Render)
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf

# Nettoyer et mettre en cache la configuration Laravel
php artisan config:cache

# Exécuter les migrations et les seeders automatiquement en production
php artisan migrate --seed --force

# Lancer le serveur web Apache au premier plan
apache2-foreground
