-- ============================================================
-- Invoice / Payment Receipt module: permissions seed
-- Adds navigation_menus rows (titles) + grants to Admin role.
-- Safe to re-run: guarded with NOT EXISTS.
-- ============================================================

INSERT INTO navigation_menus (title, status)
SELECT 'Invoice Setup', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'Invoice Setup');

INSERT INTO navigation_menus (title, status)
SELECT 'Create Invoice', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'Create Invoice');

INSERT INTO navigation_menus (title, status)
SELECT 'Invoice History', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'Invoice History');

-- Grant the new permissions to the admin role (role_name stored lowercase)
INSERT INTO role_permissions (role_name, permission_id)
SELECT 'admin', p.id
FROM navigation_menus p
WHERE p.title IN ('Invoice Setup', 'Create Invoice', 'Invoice History')
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_name = 'admin' AND rp.permission_id = p.id
);
