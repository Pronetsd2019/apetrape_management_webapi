-- Permission module for the admin Peach Payments page.
-- After running, grant "payments" read access to roles on the Roles page.
INSERT INTO modules (module_name, description)
SELECT 'payments', 'Online payments (Peach)'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE LOWER(TRIM(module_name)) = 'payments');
