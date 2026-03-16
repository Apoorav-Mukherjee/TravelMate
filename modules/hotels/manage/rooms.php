<?php
$page_title = 'Manage Rooms';
require_once __DIR__ . '/../../../includes/header.php';
require_role('hotel_staff');

$user_id = $_SESSION['user_id'];
$errors  = [];

// Get hotel
$stmt = $conn->prepare("SELECT id FROM hotels WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$my_hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$my_hotel) {
    set_flash('error', 'Please add your hotel first.');
    redirect('modules/hotels/manage/create.php');
}

$hotel_id = $my_hotel['id'];

// Handle room add/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action      = $_POST['action']       ?? 'add';
    $room_id     = (int)($_POST['room_id']       ?? 0);
    $room_type   = sanitize($_POST['room_type']  ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $base_price  = (float)($_POST['base_price']  ?? 0);
    $max_occ     = (int)($_POST['max_occupancy'] ?? 2);
    $total_rooms = (int)($_POST['total_rooms']   ?? 1);
    $amenities   = array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? '')));
    $status      = sanitize($_POST['status']     ?? 'available');

    if (empty($room_type)) $errors[] = 'Room type is required.';
    if ($base_price <= 0)  $errors[] = 'Valid price is required.';

    // Handle images
    $images = [];
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $idx => $tmp) {
            if ($_FILES['images']['error'][$idx] !== 0) continue;
            $ext     = strtolower(pathinfo($_FILES['images']['name'][$idx], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed)) continue;
            if ($_FILES['images']['size'][$idx] > 2 * 1024 * 1024) continue;
            $fname = uniqid('room_') . '.' . $ext;
            if (move_uploaded_file($tmp, UPLOAD_PATH . 'rooms/' . $fname)) {
                $images[] = $fname;
            }
        }
    }

    if (empty($errors)) {
        $amenities_json = json_encode(array_values($amenities));

        if ($action === 'add') {
            // i s s d i i s s s = 9 types, 9 values
            $stmt = $conn->prepare("
                INSERT INTO rooms
                    (hotel_id, room_type, description, base_price, max_occupancy,
                     total_rooms, amenities, images, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $images_json = json_encode($images);
            $stmt->bind_param('issdiiiss',
                $hotel_id, $room_type, $description, $base_price,
                $max_occ, $total_rooms, $amenities_json, $images_json, $status
            );
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Room added successfully.');

        } elseif ($action === 'edit' && $room_id) {
            // FIX: removed broken block with 'ssdiissi i' (space in type string)
            // Correct types: s s d i i s s i i = 9 types, 9 values
            // room_type(s) description(s) base_price(d) max_occ(i) total_rooms(i)
            // amenities(s) status(s) room_id(i) hotel_id(i)
            if ($images) {
                // Update including new images
                $images_json = json_encode($images);
                $stmt = $conn->prepare("
                    UPDATE rooms
                    SET room_type=?, description=?, base_price=?,
                        max_occupancy=?, total_rooms=?, amenities=?, status=?, images=?
                    WHERE id=? AND hotel_id=?
                ");
                $stmt->bind_param('ssdiiissii',
                    $room_type, $description, $base_price,
                    $max_occ, $total_rooms, $amenities_json, $status,
                    $images_json, $room_id, $hotel_id
                );
            } else {
                // Update without changing images
                $stmt = $conn->prepare("
                    UPDATE rooms
                    SET room_type=?, description=?, base_price=?,
                        max_occupancy=?, total_rooms=?, amenities=?, status=?
                    WHERE id=? AND hotel_id=?
                ");
                $stmt->bind_param('ssdiiisii',
                    $room_type, $description, $base_price,
                    $max_occ, $total_rooms, $amenities_json, $status,
                    $room_id, $hotel_id
                );
            }
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Room updated.');

        } elseif ($action === 'delete' && $room_id) {
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id=? AND hotel_id=?");
            $stmt->bind_param('ii', $room_id, $hotel_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Room deleted.');
        }

        redirect('modules/hotels/manage/rooms.php');
    }
}

// Fetch rooms
$stmt = $conn->prepare("SELECT * FROM rooms WHERE hotel_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/hotel_staff/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Manage Rooms</h5>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRoomModal"
                    onclick="resetModal()">
                <i class="bi bi-plus"></i> Add Room
            </button>
        </div>

        <?php $flash = get_flash(); ?>
        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mx-3 mt-3">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mx-3 mt-3">
            <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Image</th>
                                <th>Room Type</th>
                                <th>Price/Night</th>
                                <th>Max Guests</th>
                                <th>Total Rooms</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rooms as $room): ?>
                            <?php $rim = json_decode($room['images'], true) ?? []; ?>
                            <tr>
                                <td>
                                    <img src="<?= BASE_URL ?>assets/uploads/rooms/<?= htmlspecialchars($rim[0] ?? 'default.jpg') ?>"
                                         width="60" height="45"
                                         style="object-fit:cover;border-radius:6px"
                                         alt="Room image">
                                </td>
                                <td class="fw-semibold"><?= htmlspecialchars($room['room_type']) ?></td>
                                <td>₹<?= number_format($room['base_price'], 2) ?></td>
                                <td><?= $room['max_occupancy'] ?></td>
                                <td><?= $room['total_rooms'] ?></td>
                                <td>
                                    <span class="badge <?= $room['status'] === 'available' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= ucfirst($room['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="editRoom(<?= htmlspecialchars(json_encode($room)) ?>)">
                                        Edit
                                    </button>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete this room?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action"  value="delete">
                                        <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rooms)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No rooms added yet.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Add / Edit Room Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roomModalTitle">Add Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action"  value="add" id="formAction">
                <input type="hidden" name="room_id" value=""    id="roomId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Room Type *</label>
                            <input type="text" name="room_type" id="roomType" class="form-control"
                                   placeholder="e.g. Deluxe, Suite, Standard" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="roomStatus" class="form-select">
                                <option value="available">Available</option>
                                <option value="unavailable">Unavailable</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Price Per Night (₹) *</label>
                            <input type="number" name="base_price" id="roomPrice"
                                   class="form-control" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Max Occupancy</label>
                            <input type="number" name="max_occupancy" id="roomOccupancy"
                                   class="form-control" min="1" max="20" value="2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Rooms</label>
                            <input type="number" name="total_rooms" id="roomTotal"
                                   class="form-control" min="1" value="1">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" id="roomDesc"
                                      class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Amenities <small class="text-muted fw-normal">(comma-separated)</small>
                            </label>
                            <input type="text" name="amenities" id="roomAmenities"
                                   class="form-control" placeholder="AC, TV, WiFi, Hot Water">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Room Images (max 5)</label>
                            <input type="file" name="images[]" class="form-control"
                                   accept="image/*" multiple>
                            <div class="form-text" id="currentImagesNote"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetModal() {
    document.getElementById('roomModalTitle').textContent = 'Add Room';
    document.getElementById('formAction').value           = 'add';
    document.getElementById('roomId').value               = '';
    document.getElementById('roomType').value             = '';
    document.getElementById('roomPrice').value            = '';
    document.getElementById('roomOccupancy').value        = '2';
    document.getElementById('roomTotal').value            = '1';
    document.getElementById('roomDesc').value             = '';
    document.getElementById('roomStatus').value           = 'available';
    document.getElementById('roomAmenities').value        = '';
    document.getElementById('currentImagesNote').textContent = '';
}

function editRoom(room) {
    document.getElementById('roomModalTitle').textContent = 'Edit Room';
    document.getElementById('formAction').value           = 'edit';
    document.getElementById('roomId').value               = room.id;
    document.getElementById('roomType').value             = room.room_type;
    document.getElementById('roomPrice').value            = room.base_price;
    document.getElementById('roomOccupancy').value        = room.max_occupancy;
    document.getElementById('roomTotal').value            = room.total_rooms;
    document.getElementById('roomDesc').value             = room.description || '';
    document.getElementById('roomStatus').value           = room.status;

    try {
        const amenities = JSON.parse(room.amenities);
        document.getElementById('roomAmenities').value = Array.isArray(amenities)
            ? amenities.join(', ') : '';
    } catch(e) {
        document.getElementById('roomAmenities').value = '';
    }

    // Show note if room already has images
    try {
        const imgs = JSON.parse(room.images);
        const note = document.getElementById('currentImagesNote');
        note.textContent = imgs && imgs.length
            ? `Room has ${imgs.length} existing image(s). Upload new ones to replace.`
            : '';
    } catch(e) {}

    new bootstrap.Modal(document.getElementById('addRoomModal')).show();
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>