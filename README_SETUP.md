# Setup Instructions

## Installing Dependencies

This project uses Composer to manage PHP dependencies. To install the required packages:

1. Make sure you have Composer installed on your system
2. Navigate to the project root directory
3. Run the following command:

```bash
composer install
```

This will install the Firebase JWT library required for authentication.

## Database Setup

1. Create your database
2. Run the SQL scripts to create all required tables:
   - Create the tables from your schema (roles, modules, admins, role_module_permissions)
   - Run `database/refresh_tokens_table.sql` to create the refresh_tokens table

## Configuration

1. Update database credentials in `util/connect.php`:
   - Set `$db_name` to your database name
   - Set `$db_user` to your database username
   - Set `$db_pass` to your database password

2. Update JWT secret in `util/jwt.php`:
   - Change `JWT_SECRET` to a strong random string (minimum 32 characters)
   - This is critical for security in production

## API Endpoints

### Authentication
- `POST /auth/login.php` - Login and get access token
- `POST /auth/refresh.php` - Refresh access token

### Admins
- `POST /admin/create.php` - Create admin
- `PUT /admin/update.php` - Update admin
- `DELETE /admin/delete.php` - Delete admin

### Roles
- `POST /roles/create.php` - Create role
- `PUT /roles/update.php` - Update role
- `DELETE /roles/delete.php` - Delete role

### Modules
- `POST /modules/create.php` - Create module
- `PUT /modules/update.php` - Update module
- `DELETE /modules/delete.php` - Delete module

### Permissions
- `POST /role_module_permissions/create.php` - Create permission
- `PUT /role_module_permissions/update.php` - Update permission
- `DELETE /role_module_permissions/delete.php` - Delete permission

