<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ExtractPermissions extends Command
{
    protected $signature = 'permissions:extract';
    protected $description = 'Synchronise les permissions avec les annotations des contrôleurs et les catégorise par menu.';
    protected string $defaultManualModule = 'Autres Modules';

    protected array $manualPermissions = [
        'view_access_validation_and_cancel' => [
            'description' => 'Afficher les actions de validation, rejet des demandes d\'abonnement.',
            'category' => 'Permissions supplémentaires',
        ],
    ];

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $systemUser = User::where('login', 'SYSTEM')->first();
        $systemId = $systemUser?->id ?? 1;
        $superAdminRole = Role::find(1);

        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions et modules ---");

        $validPermissions = [];

        foreach ($permissions as $controller => $methods) {
            ksort($methods);
            usort($methods, fn($a, $b) => strcmp($a['permission'], $b['permission']));
            foreach ($methods as $perm) {
                $categoryName = $perm['category'] ?? 'Autres';
                $isSuperAdminCategory = $perm['is_super_admin'] ?? false;
                $modules = $perm['modules'] ?? [$this->defaultManualModule];

                $category = PermissionCategory::firstOrCreate(
                    ['libelle' => $categoryName],
                    [
                        'description' => $categoryName,
                        'is_super_admin' => $isSuperAdminCategory,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                if ($category->is_super_admin != $isSuperAdminCategory) {
                    $category->update(['is_super_admin' => $isSuperAdminCategory]);
                }

                $permission = Permission::updateOrCreate(
                    ['name' => $perm['permission']],
                    [
                        'description' => $perm['permission_desc'] ?? '',
                        'category_id' => $category->id,
                        'system' => true,
                        'active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $validPermissions[] = $permission->name;
                foreach ($modules as $moduleName) {
                    $moduleSlug = \Str::slug($moduleName);

                    $module = \App\Models\ModuleApplications::firstOrCreate(
                        ['slug' => $moduleSlug],
                        [
                            'name' => $moduleName,
                            'description' => $moduleName,
                            'is_active' => true,
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]
                    );

                    $this->info("✅ Module synchronisé : {$module->name}");

                    if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                        $module->permissions()->attach($permission->id, [
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]);
                    }
                    $permission->module_uuid = $module->uuid;
                    $permission->save();
                }

                if ($permission->wasRecentlyCreated && $superAdminRole) {
                    $superAdminRole->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                $this->info("✅ Permission synchronisée : {$permission->name}");
            }
        }

        // ----------------------- Permissions manuelles -----------------------
        foreach ($this->manualPermissions as $name => $data) {
            $categoryName = $data['category'] ?? 'AUTRES';
            $isSuperAdminCategory = filter_var($data['is_super_admin'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $modules = $data['modules'] ?? [$this->defaultManualModule];

            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $categoryName],
                [
                    'description' => $categoryName,
                    'is_super_admin' => $isSuperAdminCategory,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            if ($category->is_super_admin != $isSuperAdminCategory) {
                $category->update(['is_super_admin' => $isSuperAdminCategory]);
            }

            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'description' => $data['description'] ?? '',
                    'category_id' => $category->id,
                    'system' => true,
                    'active' => true,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $validPermissions[] = $permission->name;

            foreach ($modules as $moduleName) {
                $moduleSlug = \Str::slug($moduleName);

                $module = \App\Models\ModuleApplications::firstOrCreate(
                    ['slug' => $moduleSlug],
                    [
                        'name' => $moduleName,
                        'description' => $moduleName,
                        'is_active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $this->info("✅ Module synchronisé : {$module->name}");

                if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                    $module->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                $permission->module_uuid = $module->uuid;
                $permission->save();
            }

            if ($permission->wasRecentlyCreated && $superAdminRole) {
                $superAdminRole->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]);
            }

            $this->info("✅ Permission manuelle synchronisée : {$permission->name}");
        }

        // ----------------------- Nettoyage -----------------------
        Permission::where('system', true)
            ->whereNotIn('name', $validPermissions)
            ->get()
            ->each(function ($permission) {
                $permission->delete();
                $this->warn("🗑️ Permission supprimée : {$permission->name}");
            });

        $usedCategoryIds = Permission::pluck('category_id')->unique()->filter();
        PermissionCategory::whereNotIn('id', $usedCategoryIds)->each(function ($category) {
            $category->delete();
            $this->warn("🗑️ Catégorie supprimée : {$category->libelle}");
        });

        $usedModuleIds = \DB::table('module_permission')->pluck('module_uuid')->unique()->filter();
        \App\Models\ModuleApplications::whereNotIn('uuid', $usedModuleIds)
            ->get()
            ->each(function ($module) {
                $module->delete();
                $this->warn("🗑️ Module supprimé : {$module->name}");
            });

        \App\Models\ModuleApplications::whereDoesntHave('permissions')
            ->get()
            ->each(function ($module) {
                $module->delete();
                $this->warn("🗑️ Module supprimé car vide : {$module->name}");
            });

        $this->info("\n✅ Synchronisation complète et nettoyage terminés !");
    }

    /**
     * Extraction des permissions depuis un contrôleur avec catégorie, is_super_admin et modules multiples
     */
    private function extractPermissionsFromController(string $controller, array &$permissions): void
    {
        if (!class_exists($controller)) return;

        $reflection = new \ReflectionClass($controller);

        // Docblock du contrôleur
        $doc = $reflection->getDocComment() ?: '';

        // Récupère la catégorie
        $category = $this->extractTagValue($doc, '@permission_category') ?: 'Autres';

        // Récupère le flag is_super_admin
        $isSuperAdminVal = $this->extractTagValue($doc, '@is_super_admin');
        $isSuperAdmin = filter_var($isSuperAdminVal, FILTER_VALIDATE_BOOLEAN);

        // Récupère tous les modules
        $modules = $this->extractTagValues($doc, '@permission_module');
        if (empty($modules)) {
            $modules = ['Autres Modules']; // fallback
        }

        // Parcours des méthodes publiques
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (!$docComment) continue;

            $permission     = $this->extractTagValue($docComment, '@permission');
            $permissionDesc = $this->extractTagValue($docComment, '@permission_desc') ?? '';

            if ($permission) {
                $permissions[$controller][$method->getName()] = [
                    'permission'      => $permission,
                    'permission_desc' => $permissionDesc,
                    'category'        => $category,
                    'is_super_admin'  => $isSuperAdmin,
                    'modules'         => $modules,
                ];
            }
        }
    }

    /**
     * Récupère toutes les valeurs d'un tag dans un docblock
     */
    private function extractTagValues(string $doc, string $tag): array
    {
        preg_match_all('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches);
        return isset($matches[1]) ? array_map('trim', $matches[1]) : [];
    }

    /**
     * Récupère tous les contrôleurs d'un répertoire récursivement
     */
    private function getControllers(string $directory, string $namespace = 'App\\Http\\Controllers'): array
    {
        $controllers = [];

        foreach (scandir($directory) as $file) {
            if (in_array($file, ['.', '..'])) continue;

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $controllers = array_merge(
                    $controllers,
                    $this->getControllers($fullPath, $namespace . '\\' . $file)
                );
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $controllers[] = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $controllers;
    }

    /**
     * Récupère la catégorie définie dans le contrôleur via @permission_category
     */
    private function extractControllerCategory(string $controller): ?string
    {
        $reflection = new \ReflectionClass($controller);
        return $reflection->getDocComment()
            ? $this->extractTagValue($reflection->getDocComment(), '@permission_category')
            : null;
    }

    /**
     * Extrait une seule valeur d’un tag dans un docblock
     */
    private function extractTagValue(string $doc, string $tag): ?string
    {
        return preg_match('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches)
            ? trim($matches[1])
            : null;
    }
}
