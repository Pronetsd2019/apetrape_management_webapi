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
   - Run `database/login_logs_and_locking.sql` to create the login_logs table and add locking columns to admins table
   - Run `database/suppliers_and_stores.sql` to create suppliers, contact_persons, stores, and store_operating_hours tables
   - Run `database/stock_and_inventory.sql` to create manufacturers, vehicle_models, items, store_items, and item_vehicle_models tables
   - Run `database/users_and_part_find_requests.sql` to create users and part_find_requests tables

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
- `POST /auth/signout.php` - Sign out and remove refresh token

### Admins
- `POST /admin/create.php` - Create admin
- `PUT /admin/update.php` - Update admin
- `DELETE /admin/delete.php` - Delete admin
- `POST /admin/unlock.php` - Unlock locked admin account

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

### Suppliers
- `POST /suppliers/create.php` - Create supplier
- `PUT /suppliers/update.php` - Update supplier
- `DELETE /suppliers/delete.php` - Delete supplier

### Contact Persons
- `POST /contact_persons/create.php` - Create contact person
- `PUT /contact_persons/update.php` - Update contact person
- `DELETE /contact_persons/delete.php` - Delete contact person

### Stores
- `POST /stores/create.php` - Create store
- `PUT /stores/update.php` - Update store
- `DELETE /stores/delete.php` - Delete store

### Manufacturers
- `POST /manufacturers/create.php` - Create manufacturer
- `PUT /manufacturers/update.php` - Update manufacturer
- `DELETE /manufacturers/delete.php` - Delete manufacturer

### Vehicle Models
- `POST /vehicle_models/create.php` - Create vehicle model
- `PUT /vehicle_models/update.php` - Update vehicle model
- `DELETE /vehicle_models/delete.php` - Delete vehicle model

### Items/Stock
- `POST /items/create.php` - Create item/stock
- `PUT /items/update.php` - Update item/stock
- `DELETE /items/delete.php` - Delete item/stock

### Users
- `GET /users/get_all.php` - Get all users
- `POST /users/create.php` - Create user

### Part Find Requests
- `GET /part_find_requests/get_all.php` - Get all part find requests
- `POST /part_find_requests/create.php` - Create part find request
- `PUT /part_find_requests/update_status.php` - Update request status
- `POST /part_find_requests/send_email.php` - Send email to user

