# Matrix MLM Pro - WordPress Plugin

A comprehensive, feature-rich Matrix MLM (Multi-Level Marketing) WordPress plugin with multiple matrix plan structures, integrated payment gateways, user dashboards, and a powerful admin interface.

## Features

### Matrix Plans
- **2x3 Matrix** - Starter plan (2 legs, 3 levels deep)
- **3x3 Matrix** - Basic plan (3 legs, 3 levels deep)
- **5x5 Matrix** - Standard plan (5 legs, 5 levels deep)
- **4x7 Matrix** - Pro plan (4 legs, 7 levels deep)
- **5x7 Matrix** - Premium plan (5 legs, 7 levels deep)
- **3x9 Matrix** - Elite plan (3 legs, 9 levels deep)
- **2x12 Matrix** - Ultimate plan (2 legs, 12 levels deep)
- Dynamic matrix settings with unlimited levels
- Auto-placement using BFS algorithm
- Matrix completion detection and bonus payout
- Re-entry support after matrix completion

### Payment Gateways
- **Paystack** - Full integration with webhook support
- **Flutterwave** - Full integration with webhook support
- Configurable charges (fixed + percentage)
- Multi-currency support (NGN, GHS, KES, ZAR, USD, GBP, EUR)

### User Dashboard
- **Deposit Management** - Fund wallet via Paystack or Flutterwave
- **Deposit History** - View all deposit records
- **Withdraw Management** - Request withdrawals with method selection
- **Withdraw History** - Track withdrawal status
- **Transaction Logs** - Complete credit/debit history
- **Referral Users** - View all referred users
- **Referral Commission** - Track referral earnings
- **Level Commission** - View level-based earnings
- **Profile Management** - Update personal information
- **E-Pin Recharge** - Redeem e-pins for wallet credit
- **Balance Transfer** - Send funds to other users
- **Recharge Logs** - E-pin usage history
- **Support Ticket Desk** - Create and manage support tickets
- **2FA Security** - TOTP-based two-factor authentication

### Admin Features
- **Manage Users** - View, ban/unban, add/subtract balance, view details
- **Manage Plans** - Create/edit matrix plans with custom commissions
- **Manage Created Pins** - Generate and track e-pins
- **Manage Payment Gateways** - Configure Paystack & Flutterwave
- **Manage Deposits** - View, approve/reject deposits
- **Manage Withdrawals** - Approve/reject with refund on rejection
- **Manage Support Tickets** - Reply to and close tickets
- **Manage Reports** - Analytics with date filtering (today/week/month/year)
- **Manage Subscribers** - Newsletter subscriber list
- **Manage General Settings** - Site, currency, registration settings
- **Manage Extensions** - SMS provider, captcha, livechat
- **Manage Language** - Multi-language selection
- **SEO Manager** - Meta tags, OG tags, custom head code
- **Email Manager** - Notification templates and settings
- **SMS Manager** - Twilio, Nexmo, Termii support
- **Frontend Manager** - Pages, blog, FAQ, contact, footer, social
- **Manage Templates** - Email notification templates
- **Manage Pages** - Core plugin pages
- **Manage Sections** - Homepage sections
- **Manage GDPR Cookie** - Cookie consent banner
- **Manage Custom CSS** - Custom styling
- **Blog Section** - Enable/configure blog display
- **Contact Us** - Contact information management
- **FAQ Section** - JSON-based FAQ management
- **Footer Section** - Footer text and about
- **Policy Pages** - Privacy, Terms, Refund policies
- **Social Icons** - All major social platforms
- **Subscribe Section** - Newsletter subscription

### Security & Compliance
- **2FA Security** - TOTP authentication with QR codes
- **GDPR Policy** - Cookie consent, data export, data deletion requests
- **Security Captcha** - Google reCAPTCHA v2 integration
- **Email Verification** - Verify email on registration
- **SMS Verification** - Phone number verification
- **Input Sanitization** - All inputs properly sanitized
- **Nonce Verification** - CSRF protection on all forms
- **Capability Checks** - Role-based access control

### Additional Features
- **Livechat** - Supports Tawk.to, Crisp, Intercom, or any widget
- **Multi-language** - 10+ languages including African languages
- **Responsive Design** - Mobile-first, works on all devices
- **Cross-browser Compatible** - Chrome, Firefox, Safari, Edge
- **Clean Modern UI** - Professional dashboard design
- **WordPress Coding Standards** - Clean, maintainable code
- **REST API** - Payment callbacks and webhooks
- **WP-Cron** - Scheduled tasks for maintenance

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher

## Installation

1. Download the plugin zip file
2. Go to WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload the zip file and activate
4. Navigate to Matrix MLM in the admin sidebar
5. Configure your payment gateway keys under Gateways
6. Create/modify plans under Plans
7. Configure settings under Settings

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[matrix_dashboard]` | User dashboard (requires login) |
| `[matrix_login]` | Login form |
| `[matrix_register]` | Registration form |
| `[matrix_plans]` | Display available plans |

## Webhook URLs

Configure these in your payment gateway dashboards:

- **Paystack Webhook:** `https://yoursite.com/wp-json/matrix-mlm/v1/payment/callback/paystack`
- **Flutterwave Webhook:** `https://yoursite.com/wp-json/matrix-mlm/v1/payment/callback/flutterwave`

## File Structure

```
matrix-mlm/
├── matrix-mlm.php              # Main plugin file
├── uninstall.php               # Clean uninstall handler
├── includes/
│   ├── class-matrix-activator.php
│   ├── class-matrix-commission.php
│   ├── class-matrix-core.php
│   ├── class-matrix-database.php
│   ├── class-matrix-deactivator.php
│   ├── class-matrix-epin.php
│   ├── class-matrix-gdpr.php
│   ├── class-matrix-language.php
│   ├── class-matrix-notifications.php
│   ├── class-matrix-plan-engine.php
│   ├── class-matrix-seo.php
│   ├── class-matrix-support.php
│   ├── class-matrix-two-factor.php
│   ├── class-matrix-user.php
│   ├── class-matrix-wallet.php
│   ├── admin/
│   │   ├── class-matrix-admin.php
│   │   ├── class-matrix-admin-deposits.php
│   │   ├── class-matrix-admin-frontend.php
│   │   ├── class-matrix-admin-gateways.php
│   │   ├── class-matrix-admin-plans.php
│   │   ├── class-matrix-admin-reports.php
│   │   ├── class-matrix-admin-settings.php
│   │   ├── class-matrix-admin-tickets.php
│   │   ├── class-matrix-admin-users.php
│   │   └── class-matrix-admin-withdrawals.php
│   └── user/
│       ├── class-matrix-user-dashboard.php
│       ├── class-matrix-user-deposits.php
│       ├── class-matrix-user-epin.php
│       ├── class-matrix-user-profile.php
│       ├── class-matrix-user-referrals.php
│       ├── class-matrix-user-tickets.php
│       ├── class-matrix-user-transfer.php
│       └── class-matrix-user-withdrawals.php
├── gateways/
│   ├── class-matrix-paystack.php
│   └── class-matrix-flutterwave.php
├── admin/
│   ├── css/matrix-admin.css
│   └── js/matrix-admin.js
├── public/
│   ├── css/
│   │   ├── matrix-public.css
│   │   └── matrix-dashboard.css
│   ├── js/matrix-public.js
│   └── templates/
│       ├── login.php
│       ├── register.php
│       └── plans.php
└── languages/
```

## Commission Structure

- **Referral Commission** - Paid to direct sponsor when someone joins
- **Level Commission** - Paid to upline members based on matrix depth
- **Matrix Completion Bonus** - Paid when entire matrix tree is filled

## License

GPL v2 or later
