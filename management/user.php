<?php
// ============================================================================
// AUTHLY – PROJECT USER MANAGEMENT (DB MENU ONLY)
// PFAD: /management/user.php
// ============================================================================

require_once __DIR__ . '/../db/config.php';
require_once __DIR__ . '/../functions/menu.php';
require_once __DIR__ . '/../functions/icons.php';
require_once __DIR__ . '/../functions/dbfunctions.php';
require_once __DIR__ . '/../functions/db_project_functions.php';

session_start();

// Login prüfen
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

// Aktives Projekt prüfen
if (!isset($_SESSION['active_project']) || !is_numeric($_SESSION['active_project'])) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Kein Projekt ausgewählt.</p>");
}

$projectId = (int)$_SESSION['active_project'];
$ownerId   = (int)$_SESSION['user_id'];
$userRole  = (string)($_SESSION['role_id'] ?? '');
$userName  = (string)($_SESSION['username'] ?? '');

// Projekt prüfen
$project = getProjectByIdAndOwner($projectId, $ownerId);
if (!$project) {
    die("<p style='color:red;font-size:22px;text-align:center;margin-top:50px;'>❌ Zugriff verweigert.</p>");
}

// -----------------------------------------------------------------------------
// USER ANLEGEN
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $token    = trim($_POST['token'] ?? ''); // optional (project_tokens)

    $email = $email !== '' ? $email : null;
    $token = $token !== '' ? $token : null;

    if ($username === '' || $password === '') {
        $error = "Bitte Username & Password eingeben.";
    } else {
        $hashed = password_hash($password, PASSWORD_ARGON2ID);

        // Optional: Token anwenden (expires_at setzen + token als benutzt markieren)
        $expiresAt = null;
        if ($token !== null) {
            try {
                global $pdo;

                $st = $pdo->prepare("SELECT id, days, used FROM project_tokens WHERE project_id = :pid AND token = :t LIMIT 1");
                $st->execute([':pid' => $projectId, ':t' => $token]);
                $tk = $st->fetch(PDO::FETCH_ASSOC);

                if (!$tk) {
                    $error = 'Token nicht gefunden.';
                } elseif ((int)$tk['used'] === 1) {
                    $error = 'Token wurde bereits benutzt.';
                } else {
                    $days = (int)($tk['days'] ?? 0);
                    if ($days > 0) {
                        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));
                    }

                    $up = $pdo->prepare("UPDATE project_tokens SET used = 1, used_by = :ub WHERE id = :id");
                    $up->execute([':ub' => $username, ':id' => (int)$tk['id']]);
                }
            } catch (Throwable $e) {
                $error = 'Token-Validierung fehlgeschlagen.';
            }
        }

        if (!isset($error)) {
            // createProjectUser() erwartet Rank als int
            $created = createProjectUser(
                $projectId,
                $username,
                $email,
                $hashed,
                $token,
                0
            );
        } else {
            $created = false;
        }

        if ($created) {
            header("Location: user.php?created=1");
            exit;
        } else {
            if (empty($error)) $error = "Fehler beim Erstellen des Users.";
        }
    }
}

// -----------------------------------------------------------------------------
// USER LADEN
// -----------------------------------------------------------------------------
$users = getProjectUsers($projectId);

// Users als JSON fürs Frontend (Search/Paging ohne DOM-Hacks)
$usersClient = [];
foreach ($users as $u) {
    $rankId = isset($u['Rank_id']) ? (int)$u['Rank_id'] : 0;
    $usersClient[] = [
        'id' => (int)($u['id'] ?? 0),
        'project_id' => (int)$projectId,
        'username' => (string)($u['username'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'expires_at' => (string)($u['expires_at'] ?? ''),
        'hwid' => (string)($u['hwid'] ?? ''),
        'ip' => (string)($u['ip'] ?? ''),
        'Rank_id' => (int)$rankId,
        'subscription_paused' => (int)($u['subscription_paused'] ?? 0),
        'banned' => (int)($u['banned'] ?? 0),
        'UserVar' => (string)($u['UserVar'] ?? ''),
    ];
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Project Users – <?= htmlspecialchars($project['name'] ?? '') ?></title>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.5/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="/gui/css/style.css" rel="stylesheet">
    <link href="/gui/css/sidebar.css" rel="stylesheet">
    <link href="/gui/css/project-settings.css?v=1" rel="stylesheet">

    <style>[x-cloak]{display:none!important;}</style>
</head>

<body class="bg-gray-900 text-gray-200 font-inter flex">

<!-- SIDEBAR -->
<aside class="sidebar fixed left-0 top-0 h-screen w-64 flex flex-col overflow-y-auto
             bg-gray-900/90 backdrop-blur-lg border border-gray-800/50 rounded-3xl m-4 shadow-xl">

    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800/50">
        <div class="flex items-center gap-2">
            <svg class="w-6 h-6 text-violet-400" viewBox="0 0 24 24">
                <path d="M12 2L20 20H4L12 2Z" />
            </svg>
            <span class="text-gray-100 font-semibold text-lg tracking-wide">Authly</span>
        </div>
    </div>

    <nav class="flex-1 px-2 mt-4">
        <?php
            // DB MENU ONLY
            echo render_menu_dynamic($_SERVER['REQUEST_URI'], (string)$userRole);
        ?>
    </nav>

    <div class="mt-auto px-4 py-3 border-t border-gray-800/50">
        <div class="flex items-center justify-between text-gray-400 text-xs">
            <span>© <?= date('Y') ?> Authly</span>
            <a href="/logout.php" class="hover:text-violet-400 transition">Logout</a>
        </div>
    </div>
</aside>

<!-- MAIN -->
<main class="flex-1 ml-72 p-10 space-y-10">

    <!-- USERS MANAGEMENT -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl"
             x-data="userTable(<?= htmlspecialchars(json_encode($usersClient, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">

        <h1 class="text-2xl font-bold text-gray-100 mb-1">Users Management :</h1>
        <p class="text-gray-400 mb-6">Klicke auf einen Benutzer, um Details zu sehen.</p>

        <?php if (isset($_GET['created'])): ?>
            <p class="text-green-400 text-sm mb-4">✅ User wurde erfolgreich erstellt.</p>
        <?php endif; ?>

        <!-- Search + Per Page -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div class="flex items-center gap-2 text-sm">
                <span>Show</span>
                <select x-model.number="perPage"
                        class="bg-gray-900 border border-gray-700 rounded px-2 py-1 text-gray-300">
                    <option :value="10">10</option>
                    <option :value="25">25</option>
                    <option :value="100">100</option>
                </select>
                <span>entries</span>

                <span class="ml-3 text-xs text-gray-400" x-text="infoText()"></span>
            </div>

            <div class="w-full sm:w-auto flex items-center gap-2">
                <div class="relative w-full sm:w-[420px]">
                    <input
                        x-model="q"
                        type="text"
                        placeholder="Suchen User"
                        class="w-full bg-gray-900 border border-gray-700 rounded-xl px-4 py-2 pr-10 text-gray-100 placeholder:text-gray-500 focus:outline-none focus:ring-2 focus:ring-violet-500/40"
                    >
                    <button
                        type="button"
                        x-show="q.length"
                        x-cloak
                        @click="q=''"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-200 text-xs px-2 py-1 rounded"
                        title="Reset"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>

        <template x-if="filtered().length === 0">
            <p class="text-gray-500 italic">Keine Benutzer gefunden.</p>
        </template>

        <template x-if="filtered().length > 0">
            <div class="overflow-x-auto">
                <table class="w-full table-fixed text-sm text-gray-200">
                    <colgroup>
                        <col style="width:220px">
                        <col style="width:260px">
                        <col style="width:140px">
                        <col style="width:160px">
                        <col style="width:160px">
                        <col style="width:90px">
                        <col style="width:150px">
                        <col style="width:120px">
                        <col style="width:120px">
                    </colgroup>

                    <thead class="text-gray-400 uppercase border-b border-gray-700/60">
                    <tr>
                        <th class="px-6 py-2 text-left">Username</th>
                        <th class="px-6 py-2 text-left">Email</th>
                        <th class="px-6 py-2 text-left">Expires</th>
                        <th class="px-6 py-2 text-left">HWID</th>
                        <th class="px-6 py-2 text-left">IP</th>
                        <th class="px-6 py-2 text-left">Rank</th>
                        <th class="px-6 py-2 text-left">Sub</th>
                        <th class="px-6 py-2 text-left">Status</th>
                        <th class="px-6 py-2 text-center">Actions</th>
                    </tr>
                    </thead>

                    <!-- ONE valid tbody -->
                    <tbody class="divide-y divide-gray-800">
                    <template x-for="u in paged()" :key="u.id">
                        <template>
                            <!-- main row -->
                            <tr class="cursor-pointer hover:bg-gray-700/20 transition"
                                @click="toggleRow(u.id)">

                                <td class="px-6 py-2 truncate" x-text="u.username || '—'"></td>
                                <td class="px-6 py-2 truncate" x-text="u.email || '—'"></td>
                                <td class="px-6 py-2" x-text="u.expires_at ? u.expires_at : '—'"></td>

                                <td class="px-6 py-2 text-xs text-gray-400 truncate"
                                    x-text="u.hwid ? u.hwid : '—'"></td>

                                <td class="px-6 py-2 text-xs text-gray-300 truncate"
                                    x-text="u.ip ? u.ip : '—'"></td>

                                <td class="px-6 py-2" x-text="Number(u.Rank_id || 0)"></td>

                                <td class="px-6 py-2">
                                    <span class="font-semibold text-xs"
                                          :class="Number(u.subscription_paused) === 1 ? 'text-orange-400' : 'text-green-400'"
                                          x-text="Number(u.subscription_paused) === 1 ? 'PAUSED' : 'ACTIVE'"></span>
                                </td>

                                <td class="px-6 py-2">
                                    <span class="font-semibold text-xs"
                                          :class="Number(u.banned) === 1 ? 'text-red-400' : 'text-green-400'"
                                          x-text="Number(u.banned) === 1 ? 'BANNED' : 'ACTIVE'"></span>
                                </td>

                                <td class="px-6 py-2 text-center">
                                    <button class="text-xs px-3 py-1 rounded bg-gray-700/60 hover:bg-gray-700"
                                            @click.stop="toggleRow(u.id)">
                                        Edit
                                    </button>
                                </td>
                            </tr>

                            <!-- dropdown row -->
                            <tr x-show="isOpen(u.id)" x-cloak x-transition>
                                <td colspan="9" class="bg-gray-900 px-6 py-4 border-b border-gray-800" @click.stop>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">

                                        <!-- Column 1 -->
                                        <div class="space-y-2">
                                            <div>
                                                <label class="text-gray-300">Username</label>
                                                <input x-model="u.username"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200">
                                            </div>

                                            <div>
                                                <label class="text-gray-300">Email</label>
                                                <input x-model="u.email"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200">
                                            </div>

                                            <div>
                                                <label class="text-gray-300">Expires At</label>
                                                <input x-model="u._expires_local" type="datetime-local"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200">
                                            </div>

                                            <div>
                                                <label class="text-gray-300">Rank (Rank_id)</label>
                                                <input x-model.number="u.Rank_id" type="number" min="0"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200">
                                            </div>
                                        </div>

                                        <!-- Column 2 -->
                                        <div class="space-y-2">
                                            <div>
                                                <label class="text-gray-300">HWID</label>
                                                <textarea x-model="u.hwid"
                                                          class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200 h-16"></textarea>
                                            </div>

                                            <div>
                                                <label class="text-gray-300">UserVar</label>
                                                <textarea x-model="u.UserVar"
                                                          class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200 h-16"
                                                          placeholder="Optional"></textarea>
                                            </div>

                                            <div>
                                                <label class="text-gray-300">IP</label>
                                                <input x-model="u.ip"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200">
                                            </div>

                                            <div>
                                                <label class="text-gray-300">Neues Passwort (optional)</label>
                                                <input x-model="u._password" type="password"
                                                       class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-200"
                                                       placeholder="leer lassen = nicht ändern">
                                            </div>
                                        </div>

                                        <!-- Column 3 -->
                                        <div class="space-y-2">
                                            <button @click.stop="saveUser(u)"
                                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded text-xs">
                                                Save Changes
                                            </button>

                                            <button @click.stop="resetHWID(u)"
                                                    class="w-full bg-violet-600 hover:bg-violet-700 text-white py-2 rounded text-xs">
                                                Reset HWID
                                            </button>

                                            <button @click.stop="toggleSub(u)"
                                                    class="w-full bg-orange-600 hover:bg-orange-700 text-white py-2 rounded text-xs">
                                                <span x-text="Number(u.subscription_paused) === 1 ? 'Unpause Subscription' : 'Pause Subscription'"></span>
                                            </button>

                                            <button @click.stop="toggleBan(u)"
                                                    class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded text-xs">
                                                Ban / Unban
                                            </button>

                                            <button @click.stop="deleteUser(u)"
                                                    class="w-full bg-gray-600 hover:bg-gray-700 text-white py-2 rounded text-xs">
                                                Delete User
                                            </button>

                                            <p class="text-xs text-gray-500" x-show="u._busy" x-cloak>Saving…</p>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        </template>
                    </template>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="flex items-center justify-between mt-4 text-xs text-gray-400">
                    <div x-text="'Page ' + page + ' / ' + totalPages()"></div>
                    <div class="flex gap-2">
                        <button class="px-3 py-1 rounded bg-gray-700/60 hover:bg-gray-700"
                                :disabled="page<=1"
                                :class="page<=1 ? 'opacity-40 cursor-not-allowed' : ''"
                                @click="page = Math.max(1, page-1)">
                            Prev
                        </button>
                        <button class="px-3 py-1 rounded bg-gray-700/60 hover:bg-gray-700"
                                :disabled="page>=totalPages()"
                                :class="page>=totalPages() ? 'opacity-40 cursor-not-allowed' : ''"
                                @click="page = Math.min(totalPages(), page+1)">
                            Next
                        </button>
                    </div>
                </div>

            </div>
        </template>

    </section>

    <!-- USER CREATION FORM -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">User erstellen</h2>

        <?php if (!empty($error)): ?>
            <p class="text-red-400 text-sm mb-3"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST" class="space-y-3">
            <div>
                <label class="text-gray-300">Username</label>
                <input name="username" class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100" required>
            </div>

            <div>
                <label class="text-gray-300">Email (optional)</label>
                <input name="email" class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100">
            </div>

            <div>
                <label class="text-gray-300">Password</label>
                <input name="password" type="password" class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100" required>
            </div>

            <div>
                <label class="text-gray-300">Token (optional)</label>
                <input name="token" class="bg-gray-900 border border-gray-700 rounded px-3 py-2 w-full text-gray-100">
            </div>

            <button type="submit" name="create_user"
                    class="bg-orange-600 hover:bg-orange-700 text-white px-6 py-2 rounded shadow text-sm">
                CREATE
            </button>
        </form>
    </section>

    <!-- BULK ACTIONS -->
    <section class="bg-gray-800 rounded-2xl p-6 shadow-xl">
        <h2 class="text-xl font-semibold text-gray-100 mb-4">Bulk Actions</h2>
        <p class="text-gray-400 mb-4">Diese Aktionen betreffen alle User des Projekts.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <button id="bulkPauseSub" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-3 rounded text-xs">
                PAUSE ALL USERS SUB
            </button>
            <button id="bulkUnpauseSub" class="bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded text-xs">
                UNPAUSE ALL USERS SUB
            </button>
            <button id="bulkPurgeUsers" class="bg-red-600 hover:bg-red-700 text-white px-4 py-3 rounded text-xs">
                PURGE ALL USERS
            </button>
            <button id="bulkResetHWID" class="bg-violet-600 hover:bg-violet-700 text-white px-4 py-3 rounded text-xs">
                RESET ALL USERS HWID
            </button>
        </div>
    </section>

</main>

<script>
// ============================================================================
// PFAD: /management/user.php (JS)
// ============================================================================

function mysqlToDatetimeLocal(mysql) {
    if (!mysql) return '';
    const s = String(mysql).trim();
    if (!s) return '';
    return s.replace(' ', 'T').substring(0, 16);
}

function datetimeLocalToMysql(local) {
    if (!local) return '';
    const s = String(local).trim();
    if (!s) return '';
    // "YYYY-MM-DDTHH:MM" -> "YYYY-MM-DD HH:MM:00"
    return s.replace('T', ' ') + ':00';
}

function userTable(initialUsers) {
    const users = (Array.isArray(initialUsers) ? initialUsers : []).map(u => ({
        ...u,
        _expires_local: mysqlToDatetimeLocal(u.expires_at || ''),
        _password: '',
        _busy: false
    }));

    return {
        users,
        q: '',
        perPage: 10,
        page: 1,
        openId: null,

        norm(s) { return (s ?? '').toString().toLowerCase().trim(); },

        isOpen(id) { return this.openId === id; },

        toggleRow(id) {
            this.openId = (this.openId === id) ? null : id;
        },

        filtered() {
            const query = this.norm(this.q);
            if (!query) return this.users;

            return this.users.filter(u => {
                const hay = [
                    u.username, u.email, u.expires_at, u.hwid, u.ip,
                    String(u.Rank_id ?? ''),
                    (Number(u.banned) === 1 ? 'banned' : 'active'),
                    (Number(u.subscription_paused) === 1 ? 'paused' : 'active'),
                    u.UserVar
                ].map(x => this.norm(x)).join(' ');
                return hay.includes(query);
            });
        },

        totalPages() {
            const n = this.filtered().length;
            return Math.max(1, Math.ceil(n / this.perPage));
        },

        paged() {
            const tp = this.totalPages();
            if (this.page > tp) this.page = tp;
            if (this.page < 1) this.page = 1;

            const arr = this.filtered();
            const start = (this.page - 1) * this.perPage;
            return arr.slice(start, start + this.perPage);
        },

        infoText() {
            const total = this.users.length;
            const shown = this.filtered().length;
            if (this.q.trim() !== '' && shown !== total) return `${shown} / ${total} matching`;
            return `${total} total`;
        },

        async postAction(fd) {
            const r = await fetch('/functions/user_actions.php', { method: 'POST', body: fd });
            let j = null;
            try { j = await r.json(); } catch (e) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Request failed');
            return j;
        },

        async saveUser(u) {
            if (u._busy) return;
            u._busy = true;

            try {
                const f = new FormData();
                f.append('action', 'save');
                f.append('id', String(u.id));
                f.append('project', String(u.project_id));
                f.append('username', u.username || '');
                f.append('email', u.email || '');
                f.append('expires', u._expires_local || '');
                f.append('hwid', u.hwid || '');
                f.append('uservar', u.UserVar || '');
                f.append('ip', u.ip || '');
                f.append('rank_id', String(Number(u.Rank_id || 0)));
                if ((u._password || '').trim() !== '') f.append('password', u._password);

                await this.postAction(f);

                u.expires_at = datetimeLocalToMysql(u._expires_local);
                u._password = '';
            } catch (e) {
                alert(e.message || 'Error');
            } finally {
                u._busy = false;
            }
        },

        async resetHWID(u) {
            if (u._busy) return;
            u._busy = true;

            try {
                const f = new FormData();
                f.append('action', 'reset_hwid');
                f.append('id', String(u.id));
                f.append('project', String(u.project_id));
                await this.postAction(f);

                u.hwid = '';
            } catch (e) {
                alert(e.message || 'Error');
            } finally {
                u._busy = false;
            }
        },

        async toggleSub(u) {
            if (u._busy) return;
            u._busy = true;

            try {
                const f = new FormData();
                f.append('action', 'toggle_sub');
                f.append('id', String(u.id));
                f.append('project', String(u.project_id));
                await this.postAction(f);

                u.subscription_paused = Number(u.subscription_paused) === 1 ? 0 : 1;
            } catch (e) {
                alert(e.message || 'Error');
            } finally {
                u._busy = false;
            }
        },

        async toggleBan(u) {
            if (u._busy) return;
            u._busy = true;

            try {
                const f = new FormData();
                f.append('action', 'toggle_ban');
                f.append('id', String(u.id));
                f.append('project', String(u.project_id));
                await this.postAction(f);

                u.banned = Number(u.banned) === 1 ? 0 : 1;
            } catch (e) {
                alert(e.message || 'Error');
            } finally {
                u._busy = false;
            }
        },

        async deleteUser(u) {
            if (!confirm('Delete this user?')) return;
            if (u._busy) return;
            u._busy = true;

            try {
                const f = new FormData();
                f.append('action', 'delete');
                f.append('id', String(u.id));
                f.append('project', String(u.project_id));
                await this.postAction(f);

                this.users = this.users.filter(x => x.id !== u.id);
                if (this.openId === u.id) this.openId = null;

                const tp = this.totalPages();
                if (this.page > tp) this.page = tp;
            } catch (e) {
                alert(e.message || 'Error');
            } finally {
                u._busy = false;
            }
        }
    };
}

// =========================
// BULK BUTTONS (AJAX)
// =========================
async function runBulk(action, confirmText) {
    if (confirmText && !confirm(confirmText)) return;

    const f = new FormData();
    f.append('action', action);

    const r = await fetch('/functions/user_actions.php', { method: 'POST', body: f });
    const j = await r.json();

    if (!j.ok) {
        alert(j.error || 'Error');
        return;
    }
    location.reload();
}

document.getElementById('bulkPauseSub')?.addEventListener('click', () =>
    runBulk('bulk_pause_sub', 'Alle Subscriptions pausieren?')
);

document.getElementById('bulkUnpauseSub')?.addEventListener('click', () =>
    runBulk('bulk_unpause_sub', 'Alle Subscriptions wieder aktivieren?')
);

document.getElementById('bulkPurgeUsers')?.addEventListener('click', () =>
    runBulk('bulk_purge_users', '⚠️ Wirklich ALLE User dieses Projekts löschen? Das kann nicht rückgängig gemacht werden!')
);

document.getElementById('bulkResetHWID')?.addEventListener('click', () =>
    runBulk('bulk_reset_hwid', 'Alle HWIDs zurücksetzen?')
);
</script>

</body>
</html>
