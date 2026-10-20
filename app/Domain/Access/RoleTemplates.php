<?php

namespace App\Domain\Access;

/**
 * GENERATED from docs/source/registry.py by docs/source/build_php_contract.py. Do not edit by hand.
 * Permission catalogue (04 §11.1) and the K11 role matrix (01 §4.2) seeded for every new company.
 */
final class RoleTemplates
{
    public const PERMISSIONS = [
        'accounting.approve',
        'accounting.close_year',
        'accounting.create',
        'accounting.delete',
        'accounting.edit',
        'accounting.lock_period',
        'accounting.view',
        'banking.approve',
        'banking.create',
        'banking.delete',
        'banking.edit',
        'banking.reconcile',
        'banking.view',
        'dashboard.approve',
        'dashboard.create',
        'dashboard.delete',
        'dashboard.edit',
        'dashboard.view',
        'inventory.approve',
        'inventory.create',
        'inventory.delete',
        'inventory.edit',
        'inventory.view',
        'inventory.view_costs',
        'lc.approve',
        'lc.create',
        'lc.delete',
        'lc.edit',
        'lc.view',
        'parties.approve',
        'parties.create',
        'parties.delete',
        'parties.edit',
        'parties.view',
        'purchases.approve',
        'purchases.create',
        'purchases.delete',
        'purchases.edit',
        'purchases.view',
        'reports.approve',
        'reports.create',
        'reports.delete',
        'reports.edit',
        'reports.export',
        'reports.view',
        'sales.approve',
        'sales.create',
        'sales.delete',
        'sales.edit',
        'sales.view',
        'settings.approve',
        'settings.backup',
        'settings.create',
        'settings.delete',
        'settings.edit',
        'settings.restore',
        'settings.roles',
        'settings.users',
        'settings.view',
    ];

    /** slug => [name, permissions] (owner is the system role holding everything, BR-060) */
    public const ROLES = [
        'owner' => ['Owner', ['accounting.approve', 'accounting.close_year', 'accounting.create', 'accounting.delete', 'accounting.edit', 'accounting.lock_period', 'accounting.view', 'banking.approve', 'banking.create', 'banking.delete', 'banking.edit', 'banking.reconcile', 'banking.view', 'dashboard.approve', 'dashboard.create', 'dashboard.delete', 'dashboard.edit', 'dashboard.view', 'inventory.approve', 'inventory.create', 'inventory.delete', 'inventory.edit', 'inventory.view', 'inventory.view_costs', 'lc.approve', 'lc.create', 'lc.delete', 'lc.edit', 'lc.view', 'parties.approve', 'parties.create', 'parties.delete', 'parties.edit', 'parties.view', 'purchases.approve', 'purchases.create', 'purchases.delete', 'purchases.edit', 'purchases.view', 'reports.approve', 'reports.create', 'reports.delete', 'reports.edit', 'reports.export', 'reports.view', 'sales.approve', 'sales.create', 'sales.delete', 'sales.edit', 'sales.view', 'settings.approve', 'settings.backup', 'settings.create', 'settings.delete', 'settings.edit', 'settings.restore', 'settings.roles', 'settings.users', 'settings.view']],
        'finance' => ['Finance manager', ['accounting.approve', 'accounting.create', 'accounting.edit', 'accounting.lock_period', 'accounting.view', 'banking.approve', 'banking.create', 'banking.edit', 'banking.reconcile', 'banking.view', 'dashboard.view', 'inventory.approve', 'inventory.view', 'inventory.view_costs', 'lc.approve', 'lc.create', 'lc.edit', 'lc.view', 'parties.create', 'parties.edit', 'parties.view', 'purchases.approve', 'purchases.create', 'purchases.edit', 'purchases.view', 'reports.create', 'reports.export', 'reports.view', 'sales.approve', 'sales.create', 'sales.edit', 'sales.view', 'settings.backup', 'settings.edit', 'settings.view']],
        'accountant' => ['Accountant', ['accounting.approve', 'accounting.create', 'accounting.edit', 'accounting.view', 'banking.create', 'banking.edit', 'banking.reconcile', 'banking.view', 'dashboard.view', 'inventory.view', 'inventory.view_costs', 'lc.create', 'lc.edit', 'lc.view', 'parties.create', 'parties.edit', 'parties.view', 'purchases.create', 'purchases.edit', 'purchases.view', 'reports.create', 'reports.export', 'reports.view', 'sales.create', 'sales.edit', 'sales.view']],
        'sales' => ['Sales staff', ['dashboard.view', 'inventory.view', 'inventory.view_costs', 'parties.create', 'parties.view', 'reports.view', 'sales.create', 'sales.edit', 'sales.view']],
        'store' => ['Store keeper', ['inventory.create', 'inventory.edit', 'inventory.view', 'purchases.view']],
        'viewer' => ['Viewer', ['accounting.view', 'banking.view', 'dashboard.view', 'inventory.view', 'inventory.view_costs', 'lc.view', 'parties.view', 'purchases.view', 'reports.view', 'sales.view']],
    ];
}
