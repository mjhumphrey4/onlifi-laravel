# OnLiFi Data Directory

This directory contains all database schemas, migrations, documentation, scripts, and certificates for the OnLiFi Payment & Voucher Management System.

## Directory Structure

```
data/
├── database/           # Database schemas and migration scripts
├── documentation/      # Project documentation and deployment guides
├── scripts/           # MikroTik RouterOS scripts and utilities
└── certificates/      # YO! Payments SSL certificates
```

## Database

The `database/` folder contains:

- **mikrotik_schema.sql** - Complete schema for MikroTik/FreeRADIUS integration including voucher management
- **central_auth_schema.sql** - Multi-tenant central authentication database schema
- **add_voucher_types.sql** - Migration to add voucher types table
- **optimize_database.sql** - Database optimization scripts

### Database Setup

1. Create the main database:
```sql
CREATE DATABASE payment_mikrotik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Create the central authentication database:
```sql
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. Import schemas:
```bash
mysql -u yo -p payment_mikrotik < database/mikrotik_schema.sql
mysql -u yo -p onlifi_central < database/central_auth_schema.sql
```

4. Run Laravel migrations (from backend folder):
```bash
php artisan migrate
```

## Documentation

The `documentation/` folder contains all original project documentation including:

- Deployment guides
- Feature implementation documentation
- Configuration guides
- Troubleshooting guides
- API documentation

## Scripts

The `scripts/` folder contains MikroTik RouterOS scripts:

- **mikrotik-telemetry-script.rsc** - RouterOS script for collecting and sending telemetry data to the backend

### Installing MikroTik Script

1. Open MikroTik Winbox or WebFig
2. Go to System > Scripts
3. Create new script and paste content from `mikrotik-telemetry-script.rsc`
4. Configure the script with your backend URL
5. Create a scheduler to run the script periodically

## Certificates

The `certificates/` folder contains YO! Payments SSL certificates:

- **Yo_Uganda_Public_Certificate.crt** - Production certificate
- **Yo_Uganda_Public_Sandbox_Certificate.crt** - Sandbox/testing certificate

These certificates are used for verifying YO! Payments API responses.

## Important Notes

1. **Database Credentials**: Update database credentials in backend `.env` file
2. **Backup**: Always backup databases before running migrations
3. **Permissions**: Ensure proper file permissions for certificates
4. **Security**: Never commit sensitive data or credentials to version control

## Migration Path from Vanilla to Laravel

If migrating from the vanilla PHP version:

1. Export existing data from vanilla database
2. Create new Laravel databases
3. Run Laravel migrations
4. Import data using provided migration scripts
5. Update configuration files
6. Test all endpoints before going live

## Support

For issues or questions, refer to the documentation in the `documentation/` folder or contact the development team.
