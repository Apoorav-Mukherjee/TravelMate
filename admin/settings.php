<?php
$page_title = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$settings_file = __DIR__ . '/../config/settings.json';
$settings      = [];

if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true) ?? [];
}

$defaults = [
    'site_name'              => SITE_NAME,
    'site_email'             => 'admin@travelmate.com',
    'commission_rate'        => 10,
    'cashback_first_topup'   => 5,
    'cashback_booking'       => 2,
    'cashback_min_amount'    => 500,
    'max_wallet_topup'       => 50000,
    'min_wallet_topup'       => 10,
    'cancellation_hours_transport' => 2,
    'cancellation_hours_hotel'     => 24,
    'cancellation_hours_guide'     => 24,
    'refund_full_hours'      => 24,
    'refund_partial_percent' => 50,
    'maintenance_mode'       => 0,
    'registration_open'      => 1,
];

$settings = array_merge($defaults, $settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $new_settings = [
        'site_name'              => sanitize($_POST['site_name']          ?? ''),
        'site_email'             => sanitize($_POST['site_email']         ?? ''),
        'commission_rate'        => (float)($_POST['commission_rate']     ?? 10),
        'cashback_first_topup'   => (float)($_POST['cashback_first_topup']?? 5),
        'cashback_booking'       => (float)($_POST['cashback_booking']    ?? 2),
        'cashback_min_amount'    => (float)($_POST['cashback_min_amount'] ?? 500),
        'max_wallet_topup'       => (float)($_POST['max_wallet_topup']    ?? 50000),
        'min_wallet_topup'       => (float)($_POST['min_wallet_topup']    ?? 10),
        'cancellation_hours_transport' => (int)($_POST['cancellation_hours_transport'] ?? 2),
        'cancellation_hours_hotel'     => (int)($_POST['cancellation_hours_hotel']     ?? 24),
        'cancellation_hours_guide'     => (int)($_POST['cancellation_hours_guide']     ?? 24),
        'refund_full_hours'      => (int)($_POST['refund_full_hours']     ?? 24),
        'refund_partial_percent' => (int)($_POST['refund_partial_percent']?? 50),
        'maintenance_mode'       => isset($_POST['maintenance_mode']) ? 1 : 0,
        'registration_open'      => isset($_POST['registration_open']) ? 1 : 0,
    ];

    file_put_contents($settings_file, json_encode($new_settings, JSON_PRETTY_PRINT));
    log_admin_action($conn, $_SESSION['user_id'], 'Updated system settings', null, null);
    set_flash('success', 'Settings saved successfully.');
    redirect('admin/settings.php');
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">System Settings</h5>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="row g-4">

                <!-- General -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold">
                            <i class="bi bi-gear text-primary"></i> General
                        </div>
                        <div class="card-body row g-3">
                            <div class="col-12">
                                <label class="form-label">Site Name</label>
                                <input type="text" name="site_name"
                                       class="form-control"
                                       value="<?= htmlspecialchars($settings['site_name']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Site Email</label>
                                <input type="email" name="site_email"
                                       class="form-control"
                                       value="<?= htmlspecialchars($settings['site_email']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Default Commission Rate (%)</label>
                                <input type="number" name="commission_rate"
                                       class="form-control"
                                       value="<?= $settings['commission_rate'] ?>"
                                       min="0" max="50" step="0.5">
                            </div>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="registration_open"
                                           <?= $settings['registration_open'] ? 'checked' : '' ?>>
                                    <label class="form-check-label">Registration Open</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="maintenance_mode"
                                           <?= $settings['maintenance_mode'] ? 'checked' : '' ?>>
                                    <label class="form-check-label text-danger">
                                        Maintenance Mode
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Wallet & Cashback -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold">
                            <i class="bi bi-wallet2 text-success"></i> Wallet & Cashback
                        </div>
                        <div class="card-body row g-3">
                            <div class="col-6">
                                <label class="form-label">Min Top-up (₹)</label>
                                <input type="number" name="min_wallet_topup"
                                       class="form-control"
                                       value="<?= $settings['min_wallet_topup'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Max Top-up (₹)</label>
                                <input type="number" name="max_wallet_topup"
                                       class="form-control"
                                       value="<?= $settings['max_wallet_topup'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">First Top-up Cashback (%)</label>
                                <input type="number" name="cashback_first_topup"
                                       class="form-control"
                                       value="<?= $settings['cashback_first_topup'] ?>"
                                       min="0" max="20" step="0.5">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Booking Cashback (%)</label>
                                <input type="number" name="cashback_booking"
                                       class="form-control"
                                       value="<?= $settings['cashback_booking'] ?>"
                                       min="0" max="10" step="0.5">
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Min Booking Amount for Cashback (₹)
                                </label>
                                <input type="number" name="cashback_min_amount"
                                       class="form-control"
                                       value="<?= $settings['cashback_min_amount'] ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancellation Policy -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold">
                            <i class="bi bi-x-circle text-danger"></i> Cancellation Policy
                        </div>
                        <div class="card-body row g-3">
                            <div class="col-6">
                                <label class="form-label">Hotel Cancel Window (hrs)</label>
                                <input type="number" name="cancellation_hours_hotel"
                                       class="form-control"
                                       value="<?= $settings['cancellation_hours_hotel'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Guide Cancel Window (hrs)</label>
                                <input type="number" name="cancellation_hours_guide"
                                       class="form-control"
                                       value="<?= $settings['cancellation_hours_guide'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Transport Cancel Window (hrs)</label>
                                <input type="number" name="cancellation_hours_transport"
                                       class="form-control"
                                       value="<?= $settings['cancellation_hours_transport'] ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Full Refund Before (hrs)</label>
                                <input type="number" name="refund_full_hours"
                                       class="form-control"
                                       value="<?= $settings['refund_full_hours'] ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Partial Refund Amount (%)</label>
                                <input type="number" name="refund_partial_percent"
                                       class="form-control"
                                       value="<?= $settings['refund_partial_percent'] ?>"
                                       min="0" max="100">
                                <div class="form-text">
                                    Applied when cancelling within the window but before departure.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save -->
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        <i class="bi bi-save"></i> Save All Settings
                    </button>
                </div>

            </div>
        </form>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>