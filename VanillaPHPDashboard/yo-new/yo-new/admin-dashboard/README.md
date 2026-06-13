# PayDash - Yo Payments Admin Dashboard

A modern, elegant PHP dashboard application for managing Yo Payments transactions, withdrawals, and analytics.

## Features

### 🔐 Secure Authentication
- Role-based access control (Admin & User roles)
- Secure password hashing with bcrypt
- Session-based authentication
- Site-specific user access

### 📊 Dashboard Overview
- Real-time earnings statistics
- Today's earnings, total earnings, and total withdrawals
- Site-specific performance cards (for admin)
- Recent transactions overview
- Auto-refresh every 60 seconds

### 💳 Transactions Management
- View all transactions across sites
- Advanced filtering (All, Success, Pending, Failed, No Voucher)
- Search functionality (phone, reference, voucher code)
- Pagination support (15 items per page)
- Responsive table design

### 💰 Withdrawals
- Request new withdrawals directly from dashboard
- View withdrawal history with pagination
- Track total withdrawn and pending amounts
- Site-specific withdrawal management
- Minimum withdrawal: UGX 1,000
- Export/Print functionality

### 📈 Performance Analytics
- Week and month view options
- Interactive bar charts using Chart.js
- Daily performance calendar grid
- Best day tracking
- Average daily earnings
- Site-specific analytics

## Design

The dashboard is built with a modern dark theme inspired by the React newdashboard design:

- **Color Scheme**: Dark blue/teal theme with emerald green accents
- **Primary Color**: #10B981 (Emerald)
- **Background**: #0A1628 (Deep Blue)
- **Cards**: #1E3A5F (Blue Gray)
- **Responsive**: Mobile-first design with Tailwind CSS
- **Sidebar Navigation**: Collapsible on mobile devices

## Installation

1. **Upload Files**
   ```bash
   # The dashboard is located in:
   /var/www/html/BiteTechsystems/yo-new/admin-dashboard/
   ```

2. **Database Configuration**
   - Edit `config/database.php` if needed
   - Default credentials:
     - Host: localhost
     - Database: omada (main), payment_mikrotik (STK)
     - Username: yo
     - Password: password

3. **Ensure SQLite Database Exists**
   - The withdrawal database should be at: `../withdraw/withdrawals.db`
   - Table structure:
     ```sql
     CREATE TABLE transactions (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         transaction_id TEXT,
         username TEXT,
         phone_number TEXT,
         amount REAL,
         status TEXT,
         created_at TEXT
     );
     ```

4. **Set Permissions**
   ```bash
   chmod -R 755 /var/www/html/BiteTechsystems/yo-new/admin-dashboard/
   chmod 666 /var/www/html/BiteTechsystems/yo-new/withdraw/withdrawals.db
   ```

## User Accounts

### Default Credentials (Password: `password` for all)

1. **Admin Account**
   - Username: `admin`
   - Access: All sites and features
   
2. **Enock**
   - Username: `enock`
   - Access: Bite Tech Network site only
   
3. **Richard**
   - Username: `richard`
   - Access: Richard Network site only
   
4. **STK**
   - Username: `stk`
   - Access: STK WIFI site only

### Changing Passwords

Edit `config/auth.php` and replace the password hash:
```php
'password' => password_hash('your_new_password', PASSWORD_DEFAULT)
```

## File Structure

```
admin-dashboard/
├── config/
│   ├── auth.php          # Authentication & user management
│   └── database.php      # Database connections
├── includes/
│   ├── functions.php     # Helper functions
│   ├── header.php        # Layout header with navigation
│   └── footer.php        # Layout footer
├── index.php             # Main dashboard
├── login.php             # Login page
├── logout.php            # Logout handler
├── transactions.php      # Transactions page
├── withdrawals.php       # Withdrawals page
├── performance.php       # Performance analytics
└── README.md             # This file
```

## Database Schema

### MySQL Tables (omada & payment_mikrotik)
```sql
transactions (
    id INT PRIMARY KEY,
    external_ref VARCHAR(255),
    msisdn VARCHAR(20),
    amount DECIMAL(10,2),
    status VARCHAR(50),
    created_at DATETIME,
    origin_site VARCHAR(100),
    voucher_code VARCHAR(100)
)
```

### SQLite Table (withdrawals.db)
```sql
transactions (
    id INTEGER PRIMARY KEY,
    transaction_id TEXT,
    username TEXT,
    phone_number TEXT,
    amount REAL,
    status TEXT,
    created_at TEXT
)
```

## Features by Page

### Dashboard (index.php)
- Today's earnings card
- Total earnings card
- Total withdrawals card with balance
- Site performance cards (admin only)
- Recent 10 transactions
- Auto-refresh every 60 seconds

### Transactions (transactions.php)
- Filter tabs: All, Success, Pending, Failed, No Voucher
- Search by phone, reference, or voucher code
- Pagination (15 per page)
- Responsive table
- Site filtering for admin

### Withdrawals (withdrawals.php)
- Request withdrawal form
- Total withdrawn summary
- Pending withdrawals summary
- Withdrawal history table
- Pagination support
- Export functionality

### Performance (performance.php)
- Week/Month view toggle
- Site selector (admin only)
- Summary cards (total, average, best day)
- Interactive bar chart
- Performance calendar grid
- Color-coded performance indicators

## Security Features

- Session-based authentication
- Password hashing with bcrypt
- SQL injection protection (prepared statements)
- XSS protection (htmlspecialchars)
- Role-based access control
- CSRF protection ready (can be enhanced)

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Dependencies

- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **SQLite3**: PHP extension
- **PDO**: PHP extension
- **Tailwind CSS**: CDN (included)
- **Chart.js**: CDN (included)

## Customization

### Change Color Scheme
Edit the CSS variables in `includes/header.php`:
```css
:root {
    --primary: #10B981;  /* Change primary color */
    --background: #0A1628;  /* Change background */
    /* ... other variables */
}
```

### Add New Users
Edit `config/auth.php` and add to the `$users` array:
```php
'newuser' => [
    'password' => password_hash('password', PASSWORD_DEFAULT),
    'name' => 'New User',
    'email' => 'user@example.com',
    'role' => 'user',
    'site' => 'SiteName'
]
```

### Modify Items Per Page
Edit the `$itemsPerPage` variable in each page:
```php
$itemsPerPage = 15;  // Change to desired number
```

## Troubleshooting

### Database Connection Issues
- Check credentials in `config/database.php`
- Ensure MySQL service is running
- Verify database names exist

### SQLite Permission Issues
```bash
chmod 666 /path/to/withdrawals.db
chmod 777 /path/to/withdraw/
```

### Session Issues
- Ensure PHP session is enabled
- Check session save path permissions
- Clear browser cookies

## Support

For issues or questions, contact the development team.

## License

Proprietary - Yo Payments Dashboard
© 2024 Bite Tech Systems
