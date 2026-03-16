<?php
$page_title = 'My Bookings';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$user_id  = $_SESSION['user_id'];
$filter   = sanitize($_GET['filter'] ?? 'all');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where  = "b.user_id = ?";
$params = [$user_id];
$types  = 'i';

if ($filter !== 'all') {
    $where   .= " AND b.booking_status = ?";
    $params[] = $filter;
    $types   .= 's';
}

// Count
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM bookings b WHERE $where AND (b.notes NOT LIKE '%[__deleted__]%' OR b.notes IS NULL)");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// Fetch
$limit_params = array_merge($params, [$per_page, $offset]);
$limit_types  = $types . 'ii';
$stmt = $conn->prepare("
    SELECT b.* FROM bookings b
    WHERE $where AND (b.notes NOT LIKE '%[__deleted__]%' OR b.notes IS NULL)
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($limit_types, ...$limit_params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Editable statuses (pending or confirmed only)
$editable_statuses   = ['pending', 'confirmed'];
// Cancellable statuses
$cancellable_statuses = ['pending', 'confirmed'];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">My Bookings</h5>
        </div>

        <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mx-3 mt-3" role="alert">
            <?= $flash['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filter tabs -->
        <div class="d-flex gap-2 mb-4 flex-wrap px-3 pt-3">
            <?php
            $filters = [
                'all'       => ['All',       'secondary'],
                'pending'   => ['Pending',   'warning'],
                'confirmed' => ['Confirmed', 'success'],
                'cancelled' => ['Cancelled', 'danger'],
                'completed' => ['Completed', 'info'],
            ];
            foreach ($filters as $key => [$label, $color]): ?>
            <a href="?filter=<?= $key ?>"
               class="btn btn-sm <?= $filter === $key ? "btn-$color" : "btn-outline-$color" ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="px-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref</th>
                                    <th>Type</th>
                                    <th>Dates</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $b): ?>
                                <?php
                                    $can_edit   = in_array($b['booking_status'], $editable_statuses);
                                    $can_cancel = in_array($b['booking_status'], $cancellable_statuses);
                                    $is_done    = $b['booking_status'] === 'completed';
                                ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($b['booking_ref']) ?></code></td>
                                    <td>
                                        <?php
                                        $type_icon = match($b['booking_type']) {
                                            'hotel'     => 'building',
                                            'guide'     => 'person-badge',
                                            'transport' => 'bus-front',
                                            'package'   => 'suitcase',
                                            default     => 'tag',
                                        };
                                        ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-<?= $type_icon ?> me-1"></i>
                                            <?= ucfirst(htmlspecialchars($b['booking_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($b['check_in']): ?>
                                        <div class="small fw-semibold">
                                            <?= date('d M Y', strtotime($b['check_in'])) ?>
                                        </div>
                                        <?php if ($b['check_out'] && $b['check_out'] !== $b['check_in']): ?>
                                        <div class="small text-muted">
                                            → <?= date('d M Y', strtotime($b['check_out'])) ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold">
                                        ₹<?= number_format($b['final_amount'], 2) ?>
                                        <?php if ($b['discount_amount'] > 0): ?>
                                        <div class="small text-success">
                                            −₹<?= number_format($b['discount_amount'], 2) ?> off
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?=
                                            $b['payment_status'] === 'paid'      ? 'bg-success'          :
                                            ($b['payment_status'] === 'refunded' ? 'bg-info'             :
                                            ($b['payment_status'] === 'failed'   ? 'bg-danger'           :
                                                                                   'bg-warning text-dark'))
                                        ?>">
                                            <?= ucfirst($b['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?=
                                            $b['booking_status'] === 'confirmed' ? 'bg-success'          :
                                            ($b['booking_status'] === 'pending'  ? 'bg-warning text-dark':
                                            ($b['booking_status'] === 'cancelled'? 'bg-danger'           :
                                            ($b['booking_status'] === 'completed'? 'bg-info'             :
                                                                                   'bg-secondary')))
                                        ?>">
                                            <?= ucfirst($b['booking_status']) ?>
                                        </span>
                                    </td>

                                    <!-- ── Actions ── -->
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center flex-wrap">

                                            <!-- VIEW -->
                                            <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $b['id'] ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               title="View Details">
                                                <i class="bi bi-eye"></i>
                                                <span class="d-none d-md-inline ms-1">View</span>
                                            </a>

                                            <!-- EDIT (only if pending/confirmed) -->
                                            <?php if ($can_edit): ?>
                                            <a href="<?= BASE_URL ?>modules/bookings/edit.php?id=<?= $b['id'] ?>"
                                               class="btn btn-sm btn-outline-secondary"
                                               title="Edit Booking">
                                                <i class="bi bi-pencil"></i>
                                                <span class="d-none d-md-inline ms-1">Edit</span>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    disabled title="Cannot edit this booking">
                                                <i class="bi bi-pencil"></i>
                                                <span class="d-none d-md-inline ms-1">Edit</span>
                                            </button>
                                            <?php endif; ?>

                                            <!-- CANCEL / DELETE -->
                                            <?php if ($can_cancel): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Cancel Booking"
                                                    onclick="confirmCancel(<?= $b['id'] ?>, '<?= htmlspecialchars($b['booking_ref'], ENT_QUOTES) ?>')">
                                                <i class="bi bi-x-circle"></i>
                                                <span class="d-none d-md-inline ms-1">Cancel</span>
                                            </button>
                                            <?php elseif ($b['booking_status'] === 'cancelled'
                                                       || $b['booking_status'] === 'completed'): ?>
                                            <!-- Soft-delete for cancelled/completed -->
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Remove from list"
                                                    onclick="confirmDelete(<?= $b['id'] ?>, '<?= htmlspecialchars($b['booking_ref'], ENT_QUOTES) ?>')">
                                                <i class="bi bi-trash"></i>
                                                <span class="d-none d-md-inline ms-1">Delete</span>
                                            </button>
                                            <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger" disabled>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php endif; ?>

                                            <!-- REVIEW (completed only) -->
                                            <?php if ($is_done): ?>
                                            <a href="<?= BASE_URL ?>modules/reviews/submit_review.php?booking_id=<?= $b['id'] ?>&type=<?= $b['booking_type'] ?>"
                                               class="btn btn-sm btn-outline-success"
                                               title="Write a Review">
                                                <i class="bi bi-star"></i>
                                                <span class="d-none d-md-inline ms-1">Review</span>
                                            </a>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
                                        No bookings found.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?filter=<?= $filter ?>&page=<?= $i ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div><!-- /px-3 -->

    </div><!-- /main-content -->
</div>

<!-- ── Cancel Confirm Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-semibold">
                    <i class="bi bi-x-circle text-danger me-2"></i>Cancel Booking
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">
                    You are about to cancel booking
                    <strong id="cancelRef"></strong>.
                    This action cannot be undone.
                </p>
                <label class="form-label small fw-semibold">
                    Reason for cancellation <span class="text-danger">*</span>
                </label>
                <textarea id="cancelReason" class="form-control" rows="3"
                          placeholder="Please provide a reason..."></textarea>
                <div id="cancelError" class="text-danger small mt-1 d-none">
                    Please enter a cancellation reason.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Keep Booking</button>
                <button type="button" class="btn btn-danger btn-sm"
                        id="confirmCancelBtn">Yes, Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-semibold">
                    <i class="bi bi-trash text-danger me-2"></i>Remove Booking
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    Remove booking <strong id="deleteRef"></strong> from your list?
                    This only hides it from your view and does not affect any records.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">Keep</button>
                <button type="button" class="btn btn-danger btn-sm"
                        id="confirmDeleteBtn">Yes, Remove</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden forms for POST actions -->
<form id="cancelForm" method="POST"
      action="cancel.php" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="booking_id" id="cancelBookingId">
    <input type="hidden" name="reason"     id="cancelReasonInput">
</form>

<form id="deleteForm" method="POST"
      action="delete.php" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="booking_id" id="deleteBookingId">
</form>

<script>
// ── Cancel ────────────────────────────────────────────────────────────────
let cancelModal, deleteModal;
document.addEventListener('DOMContentLoaded', () => {
    cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
});

function confirmCancel(id, ref) {
    document.getElementById('cancelRef').textContent    = ref;
    document.getElementById('cancelBookingId').value    = id;
    document.getElementById('cancelReason').value       = '';
    document.getElementById('cancelError').classList.add('d-none');
    cancelModal.show();
}

document.getElementById('confirmCancelBtn').addEventListener('click', () => {
    const reason = document.getElementById('cancelReason').value.trim();
    if (!reason) {
        document.getElementById('cancelError').classList.remove('d-none');
        return;
    }
    document.getElementById('cancelReasonInput').value = reason;
    document.getElementById('cancelForm').submit();
});

// ── Delete ────────────────────────────────────────────────────────────────
function confirmDelete(id, ref) {
    document.getElementById('deleteRef').textContent   = ref;
    document.getElementById('deleteBookingId').value   = id;
    deleteModal.show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    document.getElementById('deleteForm').submit();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>