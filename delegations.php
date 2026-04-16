<?php
/**
 * delegations.php — Delegation management
 *
 * Delegations let an approver temporarily hand off their approval rights
 * to a colleague for a fixed date range (e.g. while on leave).
 * Any pending approvals routed to the delegator will be re-routed to
 * the delegate for the duration.
 *
 * Uses AJAX JSON API + vanilla JS modals (matches Phase 2 style).
 */

require_once __DIR__ . '/includes/auth-check.php';

// ── AJAX handler ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    try {
        switch ($action) {

            case 'create': {
                $delegatorId = trim($input['delegator_id'] ?? '');
                $delegateId  = trim($input['delegate_id']  ?? '');
                $startsAt    = trim($input['starts_at']    ?? '');
                $endsAt      = trim($input['ends_at']      ?? '');
                $reason      = trim($input['reason']       ?? '');

                if (!$delegatorId || !$delegateId || !$startsAt || !$endsAt) {
                    throw new Exception('All fields are required.');
                }
                if ($delegatorId === $delegateId) {
                    throw new Exception('A person cannot delegate to themselves.');
                }
                if ($startsAt >= $endsAt) {
                    throw new Exception('End date must be after start date.');
                }

                $result = $sb->from('delegations')->insert([
                    'delegator_id' => $delegatorId,
                    'delegate_id'  => $delegateId,
                    'starts_at'    => $startsAt,
                    'ends_at'      => $endsAt,
                    'reason'       => $reason ?: null,
                    'is_active'    => true,
                ])->execute();

                echo json_encode(['ok' => true, 'delegation' => $result[0] ?? null]);
                break;
            }

            case 'update': {
                $id       = trim($input['id']       ?? '');
                $startsAt = trim($input['starts_at'] ?? '');
                $endsAt   = trim($input['ends_at']   ?? '');
                $reason   = trim($input['reason']    ?? '');

                if (!$id || !$startsAt || !$endsAt) {
                    throw new Exception('Missing required fields.');
                }
                if ($startsAt >= $endsAt) {
                    throw new Exception('End date must be after start date.');
                }

                $sb->from('delegations')
                    ->update([
                        'starts_at' => $startsAt,
                        'ends_at'   => $endsAt,
                        'reason'    => $reason ?: null,
                    ])
                    ->eq('id', $id)
                    ->execute();

                echo json_encode(['ok' => true]);
                break;
            }

            case 'toggle': {
                $id        = trim($input['id']        ?? '');
                $isActive  = (bool)($input['is_active'] ?? false);
                if (!$id) throw new Exception('Missing delegation ID.');

                $sb->from('delegations')
                    ->update(['is_active' => $isActive ? 'true' : 'false'])
                    ->eq('id', $id)
                    ->execute();

                echo json_encode(['ok' => true]);
                break;
            }

            case 'delete': {
                $id = trim($input['id'] ?? '');
                if (!$id) throw new Exception('Missing delegation ID.');

                $sb->from('delegations')->delete()->eq('id', $id)->execute();
                echo json_encode(['ok' => true]);
                break;
            }

            default:
                throw new Exception('Unknown action: ' . htmlspecialchars($action));
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch data ──────────────────────────────────────────
// All delegations with joined user details for both parties
$delegations = $sb->from('delegations')
    ->select('*,delegator:users!delegations_delegator_id_fkey(id,display_name,email,avatar_url),delegate:users!delegations_delegate_id_fkey(id,display_name,email,avatar_url)')
    ->execute() ?? [];

// Sort: active first, then by start date desc
usort($delegations, function ($a, $b) {
    if ((int)$a['is_active'] !== (int)$b['is_active']) {
        return (int)$b['is_active'] - (int)$a['is_active'];
    }
    return strcmp($b['starts_at'] ?? '', $a['starts_at'] ?? '');
});

// All users for the dropdowns
$allUsers = $sb->from('users')
    ->select('id,display_name,email,avatar_url')
    ->execute() ?? [];

// Today's date for "active now" badge
$today = date('Y-m-d');

$pageTitle  = 'Delegations';
$activePage = 'delegations';
require_once __DIR__ . '/includes/header.php';

// Helper: render a small user chip
function userChip(array $u, string $colorClass = 'bg-brand-50 text-brand-700'): string {
    $initials = strtoupper(substr($u['display_name'] ?? 'U', 0, 1));
    $name     = htmlspecialchars($u['display_name'] ?? '—');
    $email    = htmlspecialchars($u['email'] ?? '');
    $avatar   = htmlspecialchars($u['avatar_url'] ?? '');
    $img = $avatar
        ? "<img src=\"{$avatar}\" class=\"w-6 h-6 rounded-full object-cover\" alt=\"\">"
        : "<span class=\"w-6 h-6 rounded-full {$colorClass} flex items-center justify-center text-xs font-bold flex-shrink-0\">{$initials}</span>";
    return "<div class=\"flex items-center gap-2\">{$img}<div><div class=\"text-sm font-medium text-gray-900\">{$name}</div><div class=\"text-xs text-gray-500\">{$email}</div></div></div>";
}
?>

<!-- Page heading -->
<div class="mb-8 flex items-start justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Delegations</h1>
        <p class="mt-1 text-sm text-gray-500">
            Let approvers temporarily hand off their approval rights to a colleague —
            useful for leave, travel, or busy periods.
        </p>
    </div>
    <button onclick="openDelegationModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Delegation
    </button>
</div>

<!-- How it works banner -->
<div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 mb-8 flex gap-4">
    <svg class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
              d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/>
    </svg>
    <div class="text-sm text-blue-800">
        <strong>How delegations work:</strong> When an approval is routed to the <em>delegator</em> and an
        active delegation window covers that moment, Aurora re-routes the task to the <em>delegate</em> instead.
        The delegator receives a copy of the notification but doesn't need to act.
        Delegations only apply while <strong>active</strong> and within their date range.
    </div>
</div>

<?php if (empty($delegations)): ?>
<!-- Empty state -->
<div class="bg-white rounded-xl border border-gray-200 p-16 text-center">
    <div class="mx-auto w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center mb-4">
        <svg class="w-6 h-6 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                  d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/>
        </svg>
    </div>
    <h3 class="text-sm font-semibold text-gray-900 mb-1">No delegations yet</h3>
    <p class="text-sm text-gray-500 mb-6 max-w-sm mx-auto">
        Create a delegation to automatically re-route approvals when someone is unavailable.
    </p>
    <button onclick="openDelegationModal()"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-600 text-white text-sm font-semibold rounded-lg hover:bg-brand-700 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        New Delegation
    </button>
</div>

<?php else: ?>
<!-- Delegations table -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Delegator (away)</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Delegate (covering)</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Period</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Reason</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($delegations as $d):
                $delegator    = $d['delegator'] ?? ['display_name' => '—', 'email' => '', 'avatar_url' => ''];
                $delegate     = $d['delegate']  ?? ['display_name' => '—', 'email' => '', 'avatar_url' => ''];
                $isActive     = (bool)($d['is_active'] ?? false);
                $startsAt     = substr($d['starts_at'] ?? '', 0, 10);
                $endsAt       = substr($d['ends_at']   ?? '', 0, 10);
                $isNow        = $isActive && $today >= $startsAt && $today <= $endsAt;
                $isPast       = $endsAt < $today;
                $isFuture     = $startsAt > $today;
                $reason       = $d['reason'] ?? '';

                // Status badge
                if (!$isActive) {
                    $badgeClass = 'bg-gray-100 text-gray-500';
                    $badgeText  = 'Disabled';
                } elseif ($isNow) {
                    $badgeClass = 'bg-emerald-100 text-emerald-700';
                    $badgeText  = 'Active now';
                } elseif ($isPast) {
                    $badgeClass = 'bg-gray-100 text-gray-500';
                    $badgeText  = 'Expired';
                } else {
                    $badgeClass = 'bg-amber-100 text-amber-700';
                    $badgeText  = 'Upcoming';
                }

                // JSON for edit modal
                $editData = json_encode([
                    'id'         => $d['id'],
                    'delegator_id' => $d['delegator_id'],
                    'delegate_id'  => $d['delegate_id'],
                    'starts_at'  => $startsAt,
                    'ends_at'    => $endsAt,
                    'reason'     => $reason,
                    'is_active'  => $isActive,
                ], JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>
            <tr class="hover:bg-gray-50 transition-colors <?= !$isActive ? 'opacity-60' : '' ?>" id="row-<?= htmlspecialchars($d['id']) ?>">

                <!-- Delegator -->
                <td class="px-6 py-4"><?= userChip($delegator, 'bg-brand-100 text-brand-700') ?></td>

                <!-- Delegate -->
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                        <?= userChip($delegate, 'bg-emerald-100 text-emerald-700') ?>
                    </div>
                </td>

                <!-- Period -->
                <td class="px-6 py-4">
                    <div class="text-sm text-gray-900 font-medium">
                        <?= htmlspecialchars(date('j M Y', strtotime($startsAt))) ?>
                    </div>
                    <div class="text-xs text-gray-400">
                        to <?= htmlspecialchars(date('j M Y', strtotime($endsAt))) ?>
                    </div>
                </td>

                <!-- Status badge -->
                <td class="px-6 py-4">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $badgeClass ?>">
                        <?= $badgeText ?>
                    </span>
                </td>

                <!-- Reason -->
                <td class="px-6 py-4">
                    <span class="text-sm text-gray-500 italic">
                        <?= $reason ? htmlspecialchars($reason) : '—' ?>
                    </span>
                </td>

                <!-- Actions -->
                <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <!-- Toggle active -->
                        <button
                            onclick="toggleDelegation('<?= htmlspecialchars($d['id']) ?>', <?= $isActive ? 'false' : 'true' ?>)"
                            class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center transition-colors"
                            title="<?= $isActive ? 'Disable' : 'Enable' ?>"
                        >
                            <?php if ($isActive): ?>
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                      d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                            </svg>
                            <?php else: ?>
                            <svg class="w-4 h-4 text-gray-400 hover:text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                      d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 01-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                            </svg>
                            <?php endif; ?>
                        </button>

                        <!-- Edit -->
                        <button
                            onclick='openDelegationModal(<?= htmlspecialchars($editData, ENT_QUOTES) ?>)'
                            class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-400 hover:text-gray-600 transition-colors"
                            title="Edit"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                      d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/>
                            </svg>
                        </button>

                        <!-- Delete -->
                        <button
                            onclick="deleteDelegation('<?= htmlspecialchars($d['id']) ?>')"
                            class="w-8 h-8 rounded-lg hover:bg-red-50 flex items-center justify-center text-gray-400 hover:text-red-500 transition-colors"
                            title="Delete"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                      d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                            </svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- /overflow-x-auto -->
</div>
<?php endif; ?>


<!-- ─── Create / Edit Modal ──────────────────────────────────────────────── -->
<div id="delegation-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-900" id="modal-title">New Delegation</h2>
            <button onclick="closeDelegationModal()" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="px-6 py-5 space-y-4">
            <input type="hidden" id="del-id">

            <!-- Delegator -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Who is going away? <span class="text-red-500">*</span>
                </label>
                <select id="del-delegator"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none">
                    <option value="">Select a person…</option>
                    <?php foreach ($allUsers as $u): ?>
                    <option value="<?= htmlspecialchars($u['id']) ?>">
                        <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Delegate -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Who will cover them? <span class="text-red-500">*</span>
                </label>
                <select id="del-delegate"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none">
                    <option value="">Select a person…</option>
                    <?php foreach ($allUsers as $u): ?>
                    <option value="<?= htmlspecialchars($u['id']) ?>">
                        <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-400">Must be a different person from the delegator.</p>
            </div>

            <!-- Date range -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Starts <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="del-starts"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Ends <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="del-ends"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none">
                </div>
            </div>

            <!-- Reason -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason (optional)</label>
                <input type="text" id="del-reason" placeholder="e.g. Annual leave, Parental leave, Conference"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 focus:border-transparent outline-none">
            </div>

            <!-- Info tip -->
            <div class="bg-amber-50 rounded-lg px-4 py-3 text-xs text-amber-700">
                <strong>Note:</strong> Delegation only re-routes tasks during the specified window.
                Approvals already actioned before the delegation starts are unaffected.
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
            <button onclick="closeDelegationModal()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                Cancel
            </button>
            <button onclick="saveDelegation()" id="save-btn"
                    class="px-5 py-2 text-sm font-medium text-white bg-brand-600 rounded-lg hover:bg-brand-700">
                Save
            </button>
        </div>
    </div>
</div>


<script>
// ── Modal helpers ─────────────────────────────────────────────────────────────
function openDelegationModal(data = null) {
    document.getElementById('delegation-modal').classList.remove('hidden');

    if (data) {
        document.getElementById('modal-title').textContent  = 'Edit Delegation';
        document.getElementById('del-id').value            = data.id       || '';
        document.getElementById('del-delegator').value     = data.delegator_id || '';
        document.getElementById('del-delegate').value      = data.delegate_id  || '';
        document.getElementById('del-starts').value        = data.starts_at || '';
        document.getElementById('del-ends').value          = data.ends_at   || '';
        document.getElementById('del-reason').value        = data.reason    || '';
        // Lock people pickers when editing (changing parties is confusing)
        document.getElementById('del-delegator').disabled = true;
        document.getElementById('del-delegate').disabled  = true;
    } else {
        document.getElementById('modal-title').textContent  = 'New Delegation';
        document.getElementById('del-id').value            = '';
        document.getElementById('del-delegator').value     = '';
        document.getElementById('del-delegate').value      = '';
        document.getElementById('del-starts').value        = '';
        document.getElementById('del-ends').value          = '';
        document.getElementById('del-reason').value        = '';
        document.getElementById('del-delegator').disabled = false;
        document.getElementById('del-delegate').disabled  = false;
    }

    setTimeout(() => document.getElementById('del-starts').focus(), 100);
}

function closeDelegationModal() {
    document.getElementById('delegation-modal').classList.add('hidden');
}

// Close on backdrop click
document.getElementById('delegation-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDelegationModal();
});

// ── Save (create or update) ───────────────────────────────────────────────────
async function saveDelegation() {
    const id          = document.getElementById('del-id').value;
    const delegatorId = document.getElementById('del-delegator').value;
    const delegateId  = document.getElementById('del-delegate').value;
    const startsAt    = document.getElementById('del-starts').value;
    const endsAt      = document.getElementById('del-ends').value;
    const reason      = document.getElementById('del-reason').value.trim();

    if (!startsAt || !endsAt) { showToast('Start and end dates are required.', 'error'); return; }

    let payload;
    if (id) {
        payload = { action: 'update', id, starts_at: startsAt, ends_at: endsAt, reason };
    } else {
        if (!delegatorId || !delegateId) { showToast('Both people are required.', 'error'); return; }
        if (delegatorId === delegateId)  { showToast('A person cannot delegate to themselves.', 'error'); return; }
        payload = { action: 'create', delegator_id: delegatorId, delegate_id: delegateId, starts_at: startsAt, ends_at: endsAt, reason };
    }

    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
        await api('/delegations.php', payload);
        showToast(id ? 'Delegation updated' : 'Delegation created');
        closeDelegationModal();
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save';
    }
}

// ── Toggle active ─────────────────────────────────────────────────────────────
async function toggleDelegation(id, newState) {
    try {
        await api('/delegations.php', { action: 'toggle', id, is_active: newState });
        showToast(newState ? 'Delegation enabled' : 'Delegation disabled');
        setTimeout(() => location.reload(), 400);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteDelegation(id) {
    if (!confirmAction('Delete this delegation? The change is permanent.')) return;
    try {
        await api('/delegations.php', { action: 'delete', id });
        showToast('Delegation deleted');
        document.getElementById('row-' + id)?.remove();
        // Show empty state if table is now empty
        if (!document.querySelector('tbody tr')) location.reload();
    } catch (e) {
        showToast(e.message, 'error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
