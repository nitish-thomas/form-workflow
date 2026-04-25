<?php
/**
 * groups.php — Recipient Groups Manager
 * CRUD for recipient groups + add/remove members.
 */

require_once __DIR__ . '/includes/auth-check.php';

// ── Handle AJAX ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {
            case 'create_group':
                $result = $sb->from('recipient_groups')->insert([
                    'name'        => trim($input['name'] ?? ''),
                    'description' => trim($input['description'] ?? ''),
                ]);
                echo json_encode(['ok' => true, 'group' => $result[0] ?? null]);
                break;

            case 'update_group':
                $result = $sb->from('recipient_groups')
                    ->eq('id', $input['id'])
                    ->update([
                        'name'        => trim($input['name'] ?? ''),
                        'description' => trim($input['description'] ?? ''),
                    ]);
                echo json_encode(['ok' => true, 'group' => $result[0] ?? null]);
                break;

            case 'delete_group':
                $sb->from('recipient_groups')->eq('id', $input['id'])->delete();
                echo json_encode(['ok' => true]);
                break;

            case 'add_member':
                $result = $sb->from('group_members')->insert([
                    'group_id' => $input['group_id'],
                    'user_id'  => $input['user_id'],
                ]);
                echo json_encode(['ok' => true, 'member' => $result[0] ?? null]);
                break;

            case 'remove_member':
                $sb->from('group_members')->eq('id', $input['id'])->delete();
                echo json_encode(['ok' => true]);
                break;

            default:
                throw new Exception('Unknown action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ──────────────────────────────────────────
$groups  = $sb->from('recipient_groups')->select('*')->order('name')->execute() ?? [];
$allUsers = $sb->from('users')->select('id,email,display_name')->order('display_name')->execute() ?? [];

// Fetch all members across all groups in one call
$allMembers = $sb->from('group_members')->select('*')->execute() ?? [];

// Build maps
$userMap = [];
foreach ($allUsers as $u) { $userMap[$u['id']] = $u; }

$membersByGroup = [];
foreach ($allMembers as $m) {
    $membersByGroup[$m['group_id']][] = $m;
}

$highlight = $_GET['highlight'] ?? '';

$pageTitle  = 'Groups';
$activePage = 'groups';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Page heading -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Recipient Groups</h1>
        <p class="mt-1 text-sm text-gray-500">Manage groups of approvers that can be assigned to stages</p>
    </div>
    <button onclick="openGroupModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Group
    </button>
</div>

<!-- Groups list -->
<div class="space-y-6">
    <?php if (empty($groups)): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-gray-500 font-medium">No groups yet</p>
            <p class="text-gray-400 text-sm mt-1">Create a recipient group to organise approvers.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <?php
                $members = $membersByGroup[$group['id']] ?? [];
                $isHighlighted = ($highlight === $group['id']);
                $memberUserIds = array_column($members, 'user_id');
            ?>
            <div class="bg-white rounded-xl border <?= $isHighlighted ? 'border-brand-400 ring-2 ring-brand-100' : 'border-gray-200' ?> overflow-hidden"
                 id="group-<?= htmlspecialchars($group['id']) ?>">
                <!-- Group header -->
                <div class="px-5 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($group['name']) ?></h3>
                            <?php if (!empty($group['description'])): ?>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($group['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-medium rounded-full mr-2">
                            <?= count($members) ?> member<?= count($members) !== 1 ? 's' : '' ?>
                        </span>
                        <button onclick='openGroupModal(<?= json_encode($group) ?>)'
                                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                                title="Edit Group" aria-label="Edit group <?= htmlspecialchars($group['name']) ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button onclick="deleteGroup('<?= htmlspecialchars($group['id']) ?>', '<?= htmlspecialchars(addslashes($group['name'])) ?>')"
                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                title="Delete Group" aria-label="Delete group <?= htmlspecialchars($group['name']) ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Members section -->
                <div class="border-t border-gray-100 px-5 py-4 bg-gray-50/50">
                    <!-- Add member -->
                    <div class="flex gap-2 mb-3">
                        <select id="add-member-<?= $group['id'] ?>"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none bg-white">
                            <option value="">Add a member…</option>
                            <?php foreach ($allUsers as $u): ?>
                                <?php if (!in_array($u['id'], $memberUserIds)): ?>
                                    <option value="<?= htmlspecialchars($u['id']) ?>">
                                        <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="addMember('<?= htmlspecialchars($group['id']) ?>')"
                                class="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors shrink-0">
                            Add
                        </button>
                    </div>

                    <!-- Member list -->
                    <div class="space-y-1.5" id="members-<?= $group['id'] ?>">
                        <?php if (empty($members)): ?>
                            <p class="text-sm text-gray-400 py-2 text-center">No members in this group</p>
                        <?php endif; ?>
                        <?php foreach ($members as $m): ?>
                            <?php $u = $userMap[$m['user_id']] ?? null; ?>
                            <?php if ($u): ?>
                                <div class="flex items-center justify-between py-2 px-3 bg-white rounded-lg" id="member-<?= $m['id'] ?>">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-semibold text-xs">
                                            <?= strtoupper(substr($u['display_name'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($u['display_name']) ?></span>
                                            <span class="text-xs text-gray-400 ml-1.5"><?= htmlspecialchars($u['email']) ?></span>
                                        </div>
                                    </div>
                                    <button onclick="removeMember('<?= $m['id'] ?>')"
                                            class="p-1 text-gray-400 hover:text-red-500 rounded transition-colors"
                                            title="Remove <?= htmlspecialchars($u['display_name'] ?? '') ?> from group"
                                            aria-label="Remove <?= htmlspecialchars($u['display_name'] ?? '') ?> from group">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Group Modal ──────────────────────────────────────── -->
<div id="group-modal" class="fixed inset-0 z-40 hidden">
    <div class="fixed inset-0 bg-black/40" onclick="closeGroupModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 id="group-modal-title" class="text-lg font-semibold text-gray-900">New Group</h2>
            </div>
            <div class="px-6 py-5 space-y-4">
                <input type="hidden" id="group-id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Group Name <span class="text-red-500">*</span></label>
                    <input id="group-name" type="text" placeholder="e.g. Senior Leadership Team"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="group-desc" rows="2" placeholder="Optional description…"
                              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none resize-none"></textarea>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeGroupModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="saveGroup()"
                        class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">Save Group</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Group CRUD ──────────────────────────────────────────
function openGroupModal(group = null) {
    document.getElementById('group-modal').classList.remove('hidden');
    if (group) {
        document.getElementById('group-modal-title').textContent = 'Edit Group';
        document.getElementById('group-id').value = group.id;
        document.getElementById('group-name').value = group.name || '';
        document.getElementById('group-desc').value = group.description || '';
    } else {
        document.getElementById('group-modal-title').textContent = 'New Group';
        document.getElementById('group-id').value = '';
        document.getElementById('group-name').value = '';
        document.getElementById('group-desc').value = '';
    }
    setTimeout(() => document.getElementById('group-name').focus(), 100);
}

function closeGroupModal() {
    document.getElementById('group-modal').classList.add('hidden');
}

async function saveGroup() {
    const id   = document.getElementById('group-id').value;
    const name = document.getElementById('group-name').value.trim();
    if (!name) { showToast('Group name is required', 'error'); return; }

    const payload = {
        action:      id ? 'update_group' : 'create_group',
        id:          id || undefined,
        name:        name,
        description: document.getElementById('group-desc').value.trim(),
    };

    try {
        await api('/groups.php', payload);
        showToast(id ? 'Group updated' : 'Group created');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function deleteGroup(id, name) {
    if (!confirmAction(`Delete group "${name}"? It will be removed from any stages using it.`)) return;
    try {
        await api('/groups.php', { action: 'delete_group', id });
        showToast('Group deleted');
        document.getElementById('group-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// ── Members ─────────────────────────────────────────────
async function addMember(groupId) {
    const sel = document.getElementById('add-member-' + groupId);
    const userId = sel.value;
    if (!userId) { showToast('Select a user first', 'error'); return; }

    try {
        await api('/groups.php', { action: 'add_member', group_id: groupId, user_id: userId });
        showToast('Member added');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function removeMember(id) {
    if (!confirmAction('Remove this member from the group?')) return;
    try {
        await api('/groups.php', { action: 'remove_member', id });
        showToast('Member removed');
        document.getElementById('member-' + id)?.remove();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// Auto-open modal if ?action=new
if (new URLSearchParams(location.search).get('action') === 'new') {
    openGroupModal();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
