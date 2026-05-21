<?php
require_once 'config.php';
requireAuth('client');

$clientId = (int)$_SESSION['user_id'];
$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $businessName = trim($_POST['business_name'] ?? '');
        $economicActivity = trim($_POST['economic_activity'] ?? '');

        if ($name === '') {
            $error = 'El nombre no puede estar vacio.';
        } else {
            $pdo->prepare("UPDATE users SET name=?, phone=?, address=?, business_name=?, economic_activity=? WHERE id=?")
                ->execute([$name, $phone, $address, $businessName, $economicActivity, $clientId]);
            $_SESSION['name'] = $name;
            logClientActivity($clientId, 'profile_update', "Cliente actualizo sus datos");
            $success = 'Tus datos fueron actualizados.';
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $u = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $u->execute([$clientId]);
        $row = $u->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $error = 'La contrasena actual no es correcta.';
        } elseif (strlen($new) < 8) {
            $error = 'La nueva contrasena debe tener al menos 8 caracteres.';
        } elseif ($new !== $confirm) {
            $error = 'Las contrasenas nuevas no coinciden.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $clientId]);
            logClientActivity($clientId, 'password_change', "Cliente cambio su contrasena");
            $success = 'Contrasena actualizada correctamente.';
        }
    } elseif ($action === 'unlink_telegram') {
        $pdo->prepare("UPDATE telegram_links SET active=0 WHERE client_id=?")->execute([$clientId]);
        logClientActivity($clientId, 'telegram_unlink', "Cliente desvinculo su Telegram");
        $success = 'Telegram desvinculado.';
    }
}

$me = $pdo->prepare("SELECT * FROM users WHERE id=?");
$me->execute([$clientId]);
$user = $me->fetch();
if (!$user) { session_unset(); header('Location: login.php'); exit; }

// Asegurar codigo telegram
if (empty($user['telegram_link_code'])) {
    $user['telegram_link_code'] = tgEnsureLinkCode($clientId);
}

// Estado de vinculacion Telegram
$tgLink = $pdo->prepare("SELECT chat_id, username, first_name, last_seen_at FROM telegram_links WHERE client_id=? AND active=1 LIMIT 1");
$tgLink->execute([$clientId]);
$tgInfo = $tgLink->fetch();

$botUsername = trim(getSetting('telegram_bot_username', ''));
$tgEnabled = getSetting('telegram_enabled', '0') === '1';
$deepLink = $botUsername ? ('https://t.me/' . $botUsername . '?start=' . urlencode($user['telegram_link_code'])) : '';

$page_title = 'Mi perfil';
$page_subtitle = 'Actualiza tus datos, contrasena y conexion con Telegram.';
$main_max = 'max-w-3xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800 flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700 flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/></svg>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Datos personales -->
<div class="surface-card overflow-hidden mb-4">
    <div class="px-6 py-5 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Mis datos</h3>
        <p class="text-xs text-slate-500 mt-0.5">Esta es la informacion que tu asesor ve. Algunos campos fiscales (RNC, regimen) solo los puede cambiar el equipo.</p>
    </div>
    <form method="POST" class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="action" value="update_profile">
        <div>
            <label class="field-label">Nombre completo *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($user['name']) ?>" class="field text-sm">
        </div>
        <div>
            <label class="field-label">Email</label>
            <input type="email" value="<?= htmlspecialchars($user['email']) ?>" class="field text-sm bg-stone-50 cursor-not-allowed" disabled>
            <p class="mt-1 text-[11px] text-slate-400">Tu email no se puede cambiar. Contacta a tu asesor si necesitas actualizarlo.</p>
        </div>
        <div>
            <label class="field-label">Telefono / WhatsApp</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" class="field text-sm" placeholder="+1 809 000 0000">
        </div>
        <div>
            <label class="field-label">Nombre comercial</label>
            <input type="text" name="business_name" value="<?= htmlspecialchars($user['business_name']) ?>" class="field text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="field-label">Direccion</label>
            <input type="text" name="address" value="<?= htmlspecialchars($user['address']) ?>" class="field text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="field-label">Actividad economica</label>
            <input type="text" name="economic_activity" value="<?= htmlspecialchars($user['economic_activity']) ?>" class="field text-sm">
        </div>
        <div class="sm:col-span-2">
            <div class="rounded-xl bg-stone-50 p-4 mb-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Datos fiscales (solo lectura)</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    <div>
                        <p class="text-[10px] text-slate-400">RNC / Cedula</p>
                        <p class="font-mono font-semibold text-slate-700"><?= htmlspecialchars($user['rnc'] ?: '—') ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400">Tipo de persona</p>
                        <p class="font-semibold text-slate-700"><?= htmlspecialchars(getBusinessTypeLabel($user['business_type'])) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400">Regimen fiscal</p>
                        <p class="font-semibold text-slate-700"><?= htmlspecialchars(getTaxRegimeLabel($user['tax_regime'])) ?></p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-dark text-sm">Guardar cambios</button>
            </div>
        </div>
    </form>
</div>

<!-- Cambiar contrasena -->
<div class="surface-card overflow-hidden mb-4">
    <div class="px-6 py-5 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Cambiar contrasena</h3>
        <p class="text-xs text-slate-500 mt-0.5">Usa una contrasena fuerte de al menos 8 caracteres.</p>
    </div>
    <form method="POST" class="p-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <input type="hidden" name="action" value="change_password">
        <div>
            <label class="field-label">Contrasena actual</label>
            <input type="password" name="current_password" required class="field text-sm">
        </div>
        <div>
            <label class="field-label">Nueva contrasena</label>
            <input type="password" name="new_password" required minlength="8" class="field text-sm">
        </div>
        <div>
            <label class="field-label">Repetir nueva</label>
            <input type="password" name="confirm_password" required minlength="8" class="field text-sm">
        </div>
        <div class="sm:col-span-3 flex justify-end">
            <button type="submit" class="btn-dark text-sm">Actualizar contrasena</button>
        </div>
    </form>
</div>

<!-- Telegram -->
<div class="surface-card overflow-hidden">
    <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-slate-900">Conexion con Telegram</h3>
            <p class="text-xs text-slate-500 mt-0.5">Sube facturas directamente desde el chat enviando una foto.</p>
        </div>
        <?php if ($tgInfo): ?>
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            Conectado
        </span>
        <?php endif; ?>
    </div>

    <div class="p-6">
        <?php if (!$tgEnabled): ?>
        <div class="rounded-xl bg-stone-50 p-4 text-sm text-slate-600">
            El bot de Telegram no esta activo todavia. Tu equipo lo configurara pronto.
        </div>
        <?php elseif ($tgInfo): ?>
        <div class="rounded-xl bg-emerald-50/60 border border-emerald-100 p-4 flex items-start gap-3">
            <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-emerald-900">Tu cuenta esta conectada a Telegram</p>
                <p class="text-xs text-emerald-800 mt-1">
                    <?php if ($tgInfo['first_name'] || $tgInfo['username']): ?>
                    Como: <strong><?= htmlspecialchars($tgInfo['first_name'] ?: '@' . $tgInfo['username']) ?></strong> ·
                    <?php endif; ?>
                    Ultima actividad: <?= $tgInfo['last_seen_at'] ? date('d/m/Y H:i', strtotime($tgInfo['last_seen_at'])) : '—' ?>
                </p>
                <p class="text-xs text-slate-600 mt-2">Envia fotos al chat con <code class="font-mono">@<?= htmlspecialchars($botUsername) ?></code> y la IA las procesa automaticamente.</p>
            </div>
        </div>
        <form method="POST" class="mt-4 flex justify-end" onsubmit="return confirm('Desvincular tu Telegram? Tendras que reconectar para enviar facturas.')">
            <input type="hidden" name="action" value="unlink_telegram">
            <button type="submit" class="text-xs text-red-600 hover:text-red-800 font-semibold">Desvincular este Telegram</button>
        </form>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded-xl bg-stone-50 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Tu codigo</p>
                <div class="flex items-center gap-2">
                    <span id="tgCode" class="font-mono text-lg font-bold text-slate-900 select-all"><?= htmlspecialchars($user['telegram_link_code']) ?></span>
                    <button type="button" onclick="copyTgCode()" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">Copiar</button>
                </div>
            </div>
            <?php if ($deepLink): ?>
            <a href="<?= htmlspecialchars($deepLink) ?>" target="_blank" class="rounded-xl bg-slate-900 text-white p-4 flex items-center justify-center gap-2 hover:bg-slate-800 transition-colors">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
                <span class="text-sm font-bold">Conectar con un click</span>
            </a>
            <?php endif; ?>
        </div>
        <p class="mt-4 text-[11px] text-slate-500 leading-relaxed">
            Si prefieres hacerlo manualmente: entra a <code class="font-mono">@<?= htmlspecialchars($botUsername ?: 'BOT') ?></code> en Telegram y escribe:
            <code class="font-mono bg-stone-100 px-2 py-0.5 rounded">/vincular <?= htmlspecialchars($user['telegram_link_code']) ?></code>
        </p>
        <?php endif; ?>
    </div>
</div>

<script>
function copyTgCode() {
    const code = document.getElementById('tgCode').textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
            const el = event.target;
            const orig = el.textContent;
            el.textContent = 'Copiado';
            setTimeout(() => { el.textContent = orig; }, 1500);
        });
    }
}
</script>

<?php include 'components/layout_end.php'; ?>
