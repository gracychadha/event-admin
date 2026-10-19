-- ============================================================
-- Abstract Management: permissions seed
-- Adds navigation_menus rows (titles) + grants to Admin role.
-- Safe to re-run: guarded with NOT EXISTS.
-- ============================================================

INSERT INTO navigation_menus (title, status)
SELECT 'Add Abstract Category', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'Add Abstract Category');

INSERT INTO navigation_menus (title, status)
SELECT 'All Abstract Categories', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'All Abstract Categories');

INSERT INTO navigation_menus (title, status)
SELECT 'Add Abstract Theme', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'Add Abstract Theme');

INSERT INTO navigation_menus (title, status)
SELECT 'All Abstract Themes', '1'
WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE title = 'All Abstract Themes');

-- Grant the new permissions to the admin role (role_name is stored lowercase)
INSERT INTO role_permissions (role_name, permission_id)
SELECT 'admin', p.id
FROM navigation_menus p
WHERE p.title IN ('Add Abstract Category', 'All Abstract Categories', 'Add Abstract Theme', 'All Abstract Themes')
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_name = 'admin' AND rp.permission_id = p.id
);
