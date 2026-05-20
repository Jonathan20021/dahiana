<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: ' . getDashboardForRole($_SESSION['role']));
    exit;
}
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

$companyName     = trim(getSetting('company_name', 'Portal Asesoria'));
$companyInitials = trim(getSetting('company_initials', 'AF')) ?: 'AF';
$companySlogan   = trim(getSetting('company_slogan', 'Tu asesoria financiera a un clic'));
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesion &middot; <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body { background: #ECECEC; }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; color: #0F172A; }
        .shell { background: #fff; border-radius: 28px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .panel-illustration {
            background:
                radial-gradient(circle at 20% 15%, rgba(96, 165, 250, 0.35), transparent 45%),
                radial-gradient(circle at 80% 90%, rgba(167, 139, 250, 0.35), transparent 45%),
                linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
        }
        .field {
            width: 100%;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            padding: 13px 16px;
            font-size: 14px;
            background: #fff;
            color: #0F172A;
            transition: all .15s ease;
        }
        .field:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
    </style>
</head>
<body class="min-h-full">
<div class="min-h-screen p-3 sm:p-5 lg:p-6 flex items-center justify-center">
    <div class="shell w-full max-w-[1100px] overflow-hidden grid grid-cols-1 lg:grid-cols-5 min-h-[600px]">

        <!-- Illustration / brand panel -->
        <div class="panel-illustration relative hidden lg:flex lg:col-span-2 flex-col justify-between p-10 text-white overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-white/15 backdrop-blur flex items-center justify-center font-extrabold tracking-tight">
                        <?= htmlspecialchars(strtoupper(substr($companyInitials, 0, 2))) ?>
                    </div>
                    <div>
                        <p class="font-bold text-[15px] leading-tight"><?= htmlspecialchars($companyName) ?></p>
                        <p class="text-xs text-blue-200/80">Portal de gestion</p>
                    </div>
                </div>
            </div>

            <div class="relative z-10 space-y-6">
                <h2 class="text-3xl font-extrabold leading-tight">
                    Una vista 360<br>
                    <span class="text-blue-300">de tu cartera fiscal.</span>
                </h2>
                <p class="text-sm text-slate-300 leading-relaxed max-w-sm">
                    Igualas, tramites puntuales, finanzas y comunicacion con tus clientes en un solo lugar.
                </p>

                <div class="grid grid-cols-3 gap-3 pt-4">
                    <div class="rounded-2xl bg-white/10 backdrop-blur p-4">
                        <p class="text-xs text-blue-200/80">Solicitudes</p>
                        <p class="text-lg font-bold mt-1">24h</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 backdrop-blur p-4">
                        <p class="text-xs text-blue-200/80">Reportes</p>
                        <p class="text-lg font-bold mt-1">Vivo</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 backdrop-blur p-4">
                        <p class="text-xs text-blue-200/80">Soporte</p>
                        <p class="text-lg font-bold mt-1">WhatsApp</p>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-xs text-slate-400">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>
            </div>

            <div class="absolute -bottom-20 -right-20 w-80 h-80 rounded-full bg-blue-500/20 blur-3xl"></div>
            <div class="absolute -top-32 -left-20 w-80 h-80 rounded-full bg-indigo-500/20 blur-3xl"></div>
        </div>

        <!-- Login form -->
        <div class="lg:col-span-3 p-8 sm:p-12 lg:p-16 flex flex-col justify-center">
            <div class="max-w-sm w-full mx-auto">
                <div class="mb-8">
                    <span class="inline-flex items-center gap-2 rounded-full bg-stone-100 px-3 py-1 text-[11px] font-bold text-slate-600 uppercase tracking-wider">Bienvenido</span>
                    <h1 class="mt-4 text-3xl font-extrabold text-slate-900 tracking-tight">Inicia sesion</h1>
                    <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars($companySlogan) ?: 'Ingresa al portal con tus credenciales.' ?></p>
                </div>

                <?php if ($error): ?>
                <div class="mb-5 flex items-center gap-3 rounded-2xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700 border border-red-100">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form action="auth.php?action=login" method="POST" class="space-y-5">
                    <div>
                        <label for="email" class="block text-[12px] font-semibold text-slate-600 mb-2 uppercase tracking-wider">Correo electronico</label>
                        <input type="email" id="email" name="email" required autocomplete="email"
                               class="field" placeholder="tu@correo.com">
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label for="password" class="block text-[12px] font-semibold text-slate-600 uppercase tracking-wider">Contrasena</label>
                        </div>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                               class="field" placeholder="********">
                    </div>

                    <button type="submit"
                            class="w-full mt-4 rounded-2xl bg-slate-900 text-white py-3.5 text-sm font-bold tracking-wide hover:bg-slate-800 transition-all hover:shadow-lg">
                        Entrar al portal
                    </button>
                </form>

                <?php if (signupIsEnabled()): ?>
                <div class="mt-8 pt-6 border-t border-stone-100 text-center">
                    <p class="text-sm text-slate-500">Aun no tienes cuenta?</p>
                    <a href="signup.php" class="mt-2 inline-flex items-center gap-2 rounded-2xl bg-stone-100 hover:bg-stone-200 text-slate-900 px-5 py-2.5 text-sm font-bold transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Crear cuenta
                    </a>
                </div>
                <?php else: ?>
                <p class="mt-8 text-center text-xs text-slate-400">
                    Si tienes problemas para acceder, contacta a tu administrador.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
