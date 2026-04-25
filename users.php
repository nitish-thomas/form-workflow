<?php
/**
 * users.php — User management
 *
 * Admin-only page. Lists every account that has signed in via Google OAuth,
 * and allows admins to promote/demote between 'user' and 'admin' roles.
 *
 * Rules:
 *   - You cannot change your own role (prevents accidental self-lock-out).
 *   - Users must sign in at least once before they appear here.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/auth-check.php'; // sets $currentUser, $sb

// Admin only
if ($currentUser['role'] !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

// ── Handle AJAX POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {

            case 'set_role': {
                $targetId = $input['user_id'] ?? '';
                $newRole  = $input['role'] ?? '';

                if (!$targetId) throw new Exception('Missing user_id');
                if (!in_array($newRole, ['admin', 'user'])) throw new Exception('Invalid role');
                if ($targetId === $currentUser['id']) throw new Exception('You cannot change your own role');

                $result = $sb->from('users')
                    ->eq('id', $targetId)
                    ->update(['role' => $newRole]);

                if ($result === null) throw new Exception('Database update failed');
                echo json_encode(['ok' => true, 'role' => $newRole]);
                break;
            }

            case 'pre_register': {
                $name  = trim($input['display_name'] ?? '');
                $email = strtolower(trim($input['email'] ?? ''));

                if (!$name)  throw new Exception('Name is required');
                if (!$email) throw new Exception('Email is required');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');

                // Guard: email must not already exist
                $exists = $sb->from('users')->select('id')->eq('email', $email)->execute();
                if (!empty($exists)) throw new Exception('A user with that email already exists');

                $inserted = $sb->from('users')->insert([
                    'auth_id'      => null,   // filled in on first OAuth login
                    'email'        => $email,
                    'display_name' => $name,
                    'role'         => 'user',
                    'is_active'    => true,
                ]);
                if (!$inserted || empty($inserted[0])) throw new Exception('Database insert failed');
                echo json_encode(['ok' => true, 'user' => $inserted[0]]);
                break;
            }

            case 'set_active': {
                $targetId  = $input['user_id'] ?? '';
                $isActive  = !empty($input['is_active']);

                if (!$targetId) throw new Exception('Missing user_id');
                if ($targetId === $currentUser['id']) throw new Exception('You cannot deactivate yourself');

                $result = $sb->from('users')
                    ->eq('id', $targetId)
                    ->update(['is_active' => $isActive]);

                if ($result === null) throw new Exception('Database update failed');
                echo json_encode(['ok' => true, 'is_active' => $isActive]);
                break;
            }

            case 'set_escalation_manager': {
                $targetId   = $input['user_id']             ?? '';
                $managerId  = trim($input['manager_id']     ?? '');

                if (!$targetId) throw new Exception('Missing user_id');
                if ($targetId === $managerId) throw new Exception('A user cannot be their own escalation contact');

                // Empty string → clear the field (set NULL)
                $updateVal = $managerId ?: null;

                $result = $sb->from('users')
                    ->eq('id', $targetId)
                    ->update(['escalation_manager_id' => $updateVal]);

                if ($result === null) throw new Exception('Database update failed');
                echo json_encode(['ok' => true, 'manager_id' => $updateVal]);
                break;
            }

            default:
                throw new Exception('Unknown action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch all users ───────────────────────────────────────────────────────────
$users = $sb->from('users')->select('*')->order('display_name', true)->execute() ?? [];

// Build an ID → user map so we can look up escalation contact display names
$userMapById = [];
foreach ($users as $u) {
    $userMapById[$u['id']] = $u;
}

$pageTitle  = 'Users';
$activePage = 'users';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Page heading ──────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Users</h1>
        <p class="mt-1 text-sm text-gray-500">
            Everyone who has signed in or been pre-registered.
            Roles take effect immediately on next page load.
        </p>
    </div>
    <div class="flex items-center gap-3">
        <span class="text-sm text-gray-400 font-medium"><?= count($users) ?> registered</span>
        <button onclick="openPreRegisterModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Pre-register User
        </button>
    </div>
</div>

<!-- ── User list ─────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-x-auto">

    <?php if (empty($users)): ?>
    <div class="py-16 text-center">
        <p class="text-gray-400 text-sm">No users yet. Users appear here after their first sign-in.</p>
    </div>

    <?php else: ?>
    <table class="min-w-full divide-y divide-gray-100">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Joined</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    Escalation Contact
                    <span class="ml-1 text-gray-400 font-normal normal-case" title="Who gets notified when this person's approval is overdue">ⓘ</span>
                </th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($users as $u):
                $isSelf              = ($u['id'] === $currentUser['id']);
                $isAdmin             = ($u['role'] === 'admin');
                $isActive            = (bool)($u['is_active'] ?? true);
                $initials            = strtoupper(substr($u['display_name'] ?? $u['email'] ?? '?', 0, 1));
                $joinedAt            = $u['created_at'] ?? null;
                $escalationManagerId = $u['escalation_manager_id'] ?? null;
            ?>
            <tr class="hover:bg-gray-50/50 transition-colors" id="user-row-<?= htmlspecialchars($u['id']) ?>">

                <!-- Avatar + Name + Email -->
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($u['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt=""
                                 class="w-9 h-9 rounded-full object-cover border border-gray-200 shrink-0">
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-full bg-brand-100 border border-brand-200 flex items-center justify-center text-brand-700 font-bold text-sm shrink-0">
                                <?= $initials ?>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                <?= htmlspecialchars($u['display_name'] ?? '—') ?>
                                <?php if ($isSelf): ?>
                                <span class="text-xs font-normal text-gray-400">(you)</span>
                                <?php endif; ?>
                            </p>
                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($u['email'] ?? '—') ?></p>
                        </div>
                    </div>
                </td>

                <!-- Role badge -->
                <td class="px-6 py-4">
                    <span class="role-badge-<?= htmlspecialchars($u['id']) ?>
                                 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                 <?= $isAdmin ? 'bg-brand-100 text-brand-800' : 'bg-gray-100 text-gray-600' ?>">
                        <?= $isAdmin ? 'Admin' : 'User' ?>
                    </span>
                </td>

                <!-- Active status -->
                <td class="px-6 py-4">
                    <span class="active-badge-<?= htmlspecialchars($u['id']) ?>
                                 inline-flex items-center gap-1.5 text-xs font-medium
                                 <?= $isActive ? 'text-emerald-600' : 'text-gray-400' ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? 'bg-emerald-500' : 'bg-gray-300' ?>"></span>
                        <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                </td>

                <!-- Joined date / pending badge -->
                <td class="px-6 py-4 whitespace-nowrap">
                    <?php if (empty($u['auth_id'])): ?>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 text-xs font-medium border border-amber-200">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Pending first login
                        </span>
                    <?php else: ?>
                        <span class="text-sm text-gray-500">
                            <?= $joinedAt ? date('j M Y', strtotime($joinedAt)) : '—' ?>
                        </span>
                    <?php endif; ?>
                </td>

                <!-- Escalation Contact -->
                <td class="px-6 py-4">
                    <?php if ($isSelf): ?>
                    <span class="text-xs text-gray-400">—</span>
                    <?php else: ?>
                    <select onchange="setEscalationManager('<?= htmlspecialchars($u['id']) ?>', this.value)"
                            class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 min-w-[160px]">
                        <option value="">— None —</option>
                        <?php foreach ($users as $opt):
                            if ($opt['id'] === $u['id']) continue; // can't be own contact
                            $sel = ($escalationManagerId === $opt['id']) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($opt['id']) ?>" <?= $sel ?>>
                            <?= htmlspecialchars($opt['display_name'] ?? $opt['email'] ?? '?') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </td>

                <!-- Actions -->
                <td class="px-6 py-4 text-right">
                    <?php if ($isSelf): ?>
                    <span class="text-xs text-gray-400 italic">Cannot edit own account</span>
                    <?php else: ?>
                    <div class="flex items-center justify-end gap-2">

                        <!-- Role toggle -->
                        <button onclick="toggleRole('<?= htmlspecialchars($u['id']) ?>', '<?= $isAdmin ? 'user' : 'admin' ?>')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
                                       <?= $isAdmin
                                            ? 'border-red-200 text-red-700 hover:bg-red-50'
                                            : 'border-brand-200 text-brand-700 hover:bg-brand-50' ?>">
                            <?php if ($isAdmin): ?>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Remove admin
                            <?php else: ?>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                Make admin
                            <?php endif; ?>
                        </button>

                        <!-- Active toggle -->
                        <button onclick="toggleActive('<?= htmlspecialchars($u['id']) ?>', <?= $isActive ? 'false' : 'true' ?>)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
                                       <?= $isActive ? 'border-gray-200 text-gray-600 hover:border-red-200 hover:text-red-600 hover:bg-red-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50' ?>">
                            <?php if ($isActive): ?>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                                Deactivate
                            <?php else: ?>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Activate
                            <?php endif; ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </td>

            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>

<!-- ── Pre-register Modal ─────────────────────────────────────────────────── -->
<div id="prereg-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closePreRegisterModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-lg font-semibold text-gray-900">Pre-register User</h2>
                <p class="text-sm text-gray-500 mt-0.5">Add an Aurora staff member before their first login. They will appear in all recipient dropdowns immediately.</p>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input id="prereg-name" type="text" placeholder="e.g. Jane Smith"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Aurora Email Address <span class="text-red-500">*</span></label>
                    <input id="prereg-email" type="email" placeholder="e.g. jane.smith@auroraearlyeducation.com.au"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                    <p class="mt-1 text-xs text-gray-400">When they sign in via Google, their account links automatically by email.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closePreRegisterModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="savePreRegister()"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">Add User</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Info note ─────────────────────────────────────────────────────────── -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg px-5 py-4 flex items-start gap-3">
    <svg class="w-4 h-4 text-blue-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div class="text-sm">
        <p class="font-medium text-blue-800">How roles work</p>
        <p class="text-blue-700 mt-0.5">
            <strong>Admin</strong> — can create/edit/delete forms, stages, groups, recipients, and routing rules. Can view all submissions.
            <br>
            <strong>User</strong> — can log in to check their own submissions and act on approval requests. Cannot access form configuration.
        </p>
        <p class="text-blue-700 mt-1.5">
            Staff appear here after their first sign-in, or when pre-registered by an admin. Pre-registered users show a
            <strong>Pending first login</strong> badge — it clears automatically when they sign in with their Aurora Google account.
        </p>
    </div>
</div>

<script>
async function toggleRole(userId, newRole) {
    const label = newRole === 'admin' ? 'make this user an admin' : 'remove admin access from this user';
    if (!confirm(`Are you sure you want to ${label}?`)) return;

    try {
        const data = await api('/users.php', { action: 'set_role', user_id: userId, role: newRole });

        // Update role badge
        const badge = document.querySelector(`.role-badge-${userId}`);
        if (badge) {
            badge.textContent = newRole === 'admin' ? 'Admin' : 'User';
            badge.className = badge.className.replace(/bg-\w+-\d+ text-\w+-\d+/g, '');
            badge.classList.add(
                ...(newRole === 'admin'
                    ? ['bg-brand-100', 'text-brand-800']
                    : ['bg-gray-100', 'text-gray-600'])
            );
        }

        showToast(newRole === 'admin' ? 'User promoted to admin' : 'Admin access removed', 'success');

        // Reload the row so buttons update correctly
        setTimeout(() => location.reload(), 800);

    } catch (e) {
        showToast(e.message || 'Something went wrong', 'error');
    }
}

function openPreRegisterModal() {
    document.getElementById('prereg-name').value = '';
    document.getElementById('prereg-email').value = '';
    document.getElementById('prereg-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('prereg-name').focus(), 100);
}

function closePreRegisterModal() {
    document.getElementById('prereg-modal').classList.add('hidden');
}

async function savePreRegister() {
    const name  = document.getElementById('prereg-name').value.trim();
    const email = document.getElementById('prereg-email').value.trim();
    if (!name)  { showToast('Name is required', 'error'); return; }
    if (!email) { showToast('Email is required', 'error'); return; }

    try {
        await api('/users.php', { action: 'pre_register', display_name: name, email });
        showToast('User pre-registered — they will appear in recipient dropdowns immediately');
        closePreRegisterModal();
        setTimeout(() => location.reload(), 600);
    } catch (e) {
        showToast(e.message || 'Something went wrong', 'error');
    }
}

async function setEscalationManager(userId, managerId) {
    try {
        await api('/users.php', { action: 'set_escalation_manager', user_id: userId, manager_id: managerId });
        showToast(managerId ? 'Escalation contact saved' : 'Escalation contact cleared', 'success');
    } catch (e) {
        showToast(e.message || 'Something went wrong', 'error');
    }
}

async function toggleActive(userId, newActive) {
    const label = newActive ? 'activate this user' : 'deactivate this user';
    if (!confirm(`Are you sure you want to ${label}?`)) return;

    try {
        const data = await api('/users.php', { action: 'set_active', user_id: userId, is_active: newActive });

        showToast(newActive ? 'User activated' : 'User deactivated', 'success');
        setTimeout(() => location.reload(), 800);

    } catch (e) {
        showToast(e.message || 'Something went wrong', 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
