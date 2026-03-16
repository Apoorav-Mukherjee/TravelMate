# ✈️ TravelMate

A full-featured PHP travel booking platform built with XAMPP, supporting hotel bookings, guided tours, transport reservations, tour packages, real-time chat, and a complete wallet & payment system.

---

## 📋 Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Features](#features)
- [Project Structure](#project-structure)
- [Database Schema](#database-schema)
- [Installation](#installation)
- [Test Accounts](#test-accounts)
- [Module Documentation](#module-documentation)
- [Payment Integration](#payment-integration)
- [Admin Dashboard](#admin-dashboard)
- [Known Column Mappings](#known-column-mappings)
- [Screenshots](#screenshots)

---

## Overview

TravelMate is a multi-role travel booking web application where travelers can discover and book hotels, hire local guides, reserve transport, and purchase bundled tour packages. The platform supports multiple payment gateways (Stripe, Razorpay), an in-app wallet with cashback rewards, real-time messaging, and a comprehensive admin dashboard.

---

## Tech Stack

| Layer      | Technology                          |
|------------|-------------------------------------|
| Backend    | PHP 8.0.30 (MySQLi, no PDO)         |
| Database   | MariaDB 10.4.32                     |
| Frontend   | Bootstrap 5 + Bootstrap Icons       |
| JavaScript | Vanilla JS                          |
| Charts     | Chart.js                            |
| Server     | Apache via XAMPP (Windows)          |
| Payments   | Stripe, Razorpay                    |

---

## Features

### 👤 User Roles
- **Admin** — Full platform management, analytics, approvals
- **Traveler** — Browse and book all services
- **Hotel Staff** — Manage hotel listings and rooms
- **Guide** — Manage availability and bookings
- **Transport Provider** — Manage routes and seats

### 🏨 Hotel Booking
- Search by city, date range, price, star rating
- Room type selection with availability calendar
- Multi-night booking with check-in/check-out
- Review and rating system

### 🧭 Guide Booking
- Search guides by city and language
- Hourly or daily rate booking
- Availability calendar management
- Portfolio and specialization display

### 🚌 Transport Booking
- Bus, train, ferry, cab routes
- Seat selection (general, sleeper, AC, first class)
- Journey date and route filtering
- Real-time seat availability

### 🎁 Tour Packages
- Bundled hotel + guide + transport packages
- Multi-day itinerary builder
- Highlights, inclusions, exclusions
- Seasonal availability, featured listings
- Discount pricing support

### 💬 Real-Time Chat
- Traveler ↔ Provider messaging
- File/image sharing
- Spam word filtering
- Admin monitoring panel

### 💰 Wallet & Payments
- Wallet top-up via Stripe or Razorpay
- Wallet-to-wallet transfers
- Cashback on first top-up (5%) and bookings (2%)
- Full transaction history

### 🛡️ Admin Dashboard
- KPI cards (revenue, bookings, users, pending actions)
- 7-day revenue chart (Chart.js)
- Booking type distribution (doughnut chart)
- User management (activate/suspend/delete/add credit)
- Review moderation (approve/reject)
- Audit logs with IP tracking
- Platform settings (commission, cancellation policy, cashback rates)
- CSV exports for users and bookings

---

## Project Structure

```
travelmate/
├── admin/                    # Admin management pages
│   ├── users.php
│   ├── hotels.php
│   ├── guides.php
│   ├── transport.php
│   ├── packages.php
│   ├── reviews.php
│   ├── logs.php
│   ├── payments.php
│   ├── wallet.php
│   ├── analytics.php
│   ├── settings.php
│   ├── bookings_export.php
│   └── chat_monitor.php
├── auth/                     # Authentication
│   ├── login.php
│   ├── register.php
│   ├── verify.php
│   ├── forgot_password.php
│   └── reset_password.php
├── config/
│   ├── db.php                # Database connection
│   └── settings.json         # Platform settings
├── dashboards/
│   ├── admin/
│   │   ├── index.php
│   │   └── sidebar.php
│   ├── traveler/
│   │   ├── index.php
│   │   └── sidebar.php
│   ├── guide/
│   ├── hotel_staff/
│   └── transport_provider/
├── includes/
│   ├── header.php
│   ├── footer.php
│   └── functions.php         # Helpers, email templates, CSRF
├── modules/
│   ├── hotels/               # search, detail, book
│   ├── guides/               # search, detail, book
│   ├── transport/            # search, detail, book
│   ├── packages/             # search, detail, book, confirmation
│   ├── bookings/             # my_bookings, cancel
│   ├── reviews/              # submit_review
│   ├── chat/                 # messaging interface
│   └── wallet/               # topup, transfer, cashback
├── payments/
│   ├── checkout.php
│   ├── stripe.php
│   └── razorpay.php
├── assets/
│   ├── css/
│   ├── js/
│   └── uploads/              # hotels/, guides/, packages/
└── index.php                 # Homepage with search tabs
```

---

## Database Schema

### Tables (19 total)

| Table                   | Purpose                              |
|-------------------------|--------------------------------------|
| `roles`                 | User role definitions                |
| `users`                 | All platform users                   |
| `hotels`                | Hotel listings                       |
| `rooms`                 | Hotel room types                     |
| `guides`                | Guide profiles                       |
| `transport_providers`   | Transport company accounts           |
| `transport_routes`      | Journey routes                       |
| `transport_seats`       | Individual seat inventory            |
| `tour_packages`         | Bundled travel packages              |
| `package_components`    | Hotel/guide/transport inside package |
| `package_bookings`      | Package booking records              |
| `bookings`              | All booking records                  |
| `booking_items`         | Line items per booking               |
| `payments`              | Payment transactions                 |
| `wallet_transactions`   | Wallet credit/debit history          |
| `reviews`               | Ratings and reviews                  |
| `messages`              | Chat messages                        |
| `availability_calendars`| Entity availability overrides        |
| `admin_logs`            | Admin action audit trail             |
| `spam_words`            | Chat content moderation              |

---

## Installation

### Prerequisites
- XAMPP with PHP 8.0+ and MariaDB 10.4+
- Composer (optional, not required)
- Stripe and Razorpay API keys (for payment testing)

### Steps

**1. Clone or copy the project**
```bash
# Place in XAMPP htdocs
C:\xampp\htdocs\travelmate\
```

**2. Create the database**
```sql
CREATE DATABASE travelmate
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

**3. Import the schema**
```
phpMyAdmin → travelmate → Import → travelmate.sql
```

**4. Configure database connection**

Edit `config/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'travelmate');
define('BASE_URL', 'http://localhost/travelmate/');
```

**5. Set up upload directories**
```
assets/uploads/hotels/
assets/uploads/guides/
assets/uploads/packages/
assets/uploads/chat/
```
Make sure these are writable by Apache.

**6. Configure payment keys**

In `config/db.php` or a separate `config/payments.php`:
```php
// Stripe
define('STRIPE_PUBLIC_KEY',  'pk_test_...');
define('STRIPE_SECRET_KEY',  'sk_test_...');

// Razorpay
define('RAZORPAY_KEY_ID',    'rzp_test_...');
define('RAZORPAY_KEY_SECRET','...');
```

**7. Verify all test accounts**
```sql
UPDATE users SET email_verified = 1, status = 'active';
```

**8. Visit the homepage**
```
http://localhost/travelmate/
```

---

## Test Accounts

All accounts use the password: **`password`**

| Role               | Email                        | Password |
|--------------------|------------------------------|----------|
| Admin              | admin@travelmate.com         | password |
| Traveler           | traveler@travelmate.com      | password |
| Guide              | guide@travelmate.com         | password |
| Hotel Staff        | hotel@travelmate.com         | password |
| Transport Provider | transport@travelmate.com     | password |
| Traveler (extra)   | rahul@example.com            | password |
| Traveler (extra)   | priya@example.com            | password |

### Test Payment Cards (Stripe)
| Card Number          | Use Case       |
|----------------------|----------------|
| 4242 4242 4242 4242  | Success        |
| 4000 0000 0000 0002  | Card declined  |
| 4000 0000 0000 9995  | Insufficient funds |

Expiry: any future date — CVV: any 3 digits

---

## Module Documentation

### Authentication Flow
```
Register → Email verification (auto on localhost) → Login → Role-based dashboard
```

### Booking Flow (all modules)
```
Search → View Detail → Select options → Confirm Booking
→ Payment (Stripe / Razorpay / Wallet)
→ Booking Confirmation → Email notification
```

### Wallet Flow
```
Top-up (Stripe/Razorpay) → Wallet credited
→ Use wallet for bookings → Cashback applied
→ Transfer to other users
```

### Package Booking Flow
```
Browse packages → View detail + itinerary
→ Select travel date + persons
→ Price calculation (base + 5% GST)
→ Pay → Confirmation page
```

---

## Payment Integration

### Stripe (Credit/Debit Cards)
- Test mode supported
- Card form at `modules/wallet/topup_payment.php`
- Webhook handling in `payments/stripe.php`

### Razorpay (UPI / Net Banking)
- Test mode supported
- Redirect flow via `payments/razorpay.php`

### Wallet
- Instant deduction, no gateway fees
- Cashback credited automatically:
  - First top-up: 5% bonus
  - Every booking: 2% cashback

---

## Admin Dashboard

Access: `http://localhost/travelmate/dashboards/admin/index.php`

### KPI Cards
- Total users + new today
- Total revenue + today's revenue
- Monthly bookings and revenue
- Pending approvals count
- Active hotels, guides, transport providers

### Charts
- 7-day revenue bar chart (Chart.js)
- Booking type distribution doughnut chart

### Management Pages
| Page              | URL                          |
|-------------------|------------------------------|
| Users             | admin/users.php              |
| Hotels            | admin/hotels.php             |
| Guides            | admin/guides.php             |
| Transport         | admin/transport.php          |
| Packages          | admin/packages.php           |
| Reviews           | admin/reviews.php            |
| Audit Logs        | admin/logs.php               |
| Payments          | admin/payments.php           |
| Analytics         | admin/analytics.php          |
| Settings          | admin/settings.php           |

---

## Known Column Mappings

Several column names differ from what was originally coded. These are the **correct** names from the actual schema:

| Table            | Wrong (old code)   | Correct (actual)    |
|------------------|--------------------|---------------------|
| `tour_packages`  | `name`             | `title`             |
| `tour_packages`  | `destination`      | `city`              |
| `tour_packages`  | `price_per_person` | `fixed_price`       |
| `tour_packages`  | `created_by`       | `admin_id`          |
| `hotels`         | `user_id`          | `owner_id`          |
| `admin_logs`     | `entity_type`      | `target_type`       |
| `admin_logs`     | `entity_id`        | `target_id`         |
| `rooms`          | `capacity`         | `max_occupancy`     |
| `rooms`          | `available_rooms`  | *(column removed)*  |

> ⚠️ Always reference this table before writing SQL queries to avoid `Unknown column` errors.

---

## Security Features

- CSRF tokens on all POST forms
- Password hashing with `password_hash(BCRYPT)`
- SQL injection prevention via `real_escape_string()` / prepared statements
- XSS prevention via `htmlspecialchars()`
- Role-based access control on every page (`require_role()`)
- Soft deletes for users (preserves booking history)
- Admin action audit logging with IP address
- Spam word filtering in chat
- Email verification for new accounts

---

## Coding Conventions

```php
// Database queries — use direct query() with escaping
$val = $conn->real_escape_string($input);
$r   = $conn->query("SELECT * FROM table WHERE col = '$val'");
if ($r) $rows = $r->fetch_all(MYSQLI_ASSOC);

// Flash messages
set_flash('success', 'Action completed.');
set_flash('error',   'Something went wrong.');

// Redirects (always after POST)
redirect('modules/hotels/search.php');

// Role protection (top of every protected page)
require_role('admin');     // or 'traveler', 'guide', etc.

// CSRF protection
// In form:   <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
// On POST:   verify_csrf();

// Sanitize inputs
$value = sanitize($_POST['field'] ?? '');
```

---

## Dummy Data

Run `dummy_data.sql` in phpMyAdmin to populate all tables with:
- 6 hotels across India (Mumbai, Jaipur, Kerala, Manali, Bangalore, Goa)
- 15 rooms across all hotels
- 3 verified guides (Agra, Kerala, Manali)
- 6 transport routes (bus, train, ferry, cab)
- 100 transport seats
- 4 tour packages (Golden Triangle, Kerala, Manali, Goa)
- 7 sample bookings with payments
- Reviews, messages, wallet transactions, admin logs

---

## License

This project is for educational and development purposes.

---

## Author

Built with ❤️ using PHP, Bootstrap 5 and MariaDB.
