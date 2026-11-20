#!/usr/bin/env bash

# Port d'écoute d'Apache (Render fournit PORT)
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT:-80}/" /etc/apache2/sites-available/000-default.conf

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

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

exec apache2-foreground
