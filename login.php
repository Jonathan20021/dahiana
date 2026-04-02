<?php
require_once 'config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: ' . getDashboardForRole($_SESSION['role']));
    exit;
}
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Portal Asesoría</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
    <!-- Decorative background elements -->
    <div class="absolute top-0 -left-4 w-72 h-72 bg-blue-400 rounded-full mix-blend-multiply filter blur-2xl opacity-20 animate-blob"></div>
    <div class="absolute top-0 -right-4 w-72 h-72 bg-indigo-400 rounded-full mix-blend-multiply filter blur-2xl opacity-20 animate-blob animation-delay-2000"></div>
    <div class="absolute -bottom-8 left-20 w-72 h-72 bg-purple-400 rounded-full mix-blend-multiply filter blur-2xl opacity-20 animate-blob animation-delay-4000"></div>

    <div class="max-w-md w-full relative">
        <div class="bg-white/80 backdrop-blur-2xl rounded-[2rem] shadow-xl shadow-slate-200/50 p-8 sm:p-12 border border-white">
            
            <div class="text-center mb-8">
                <div class="mx-auto w-16 h-16 bg-gradient-to-br from-blue-600 to-indigo-600 text-white rounded-2xl flex items-center justify-center mb-6 shadow-lg shadow-blue-500/30">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" />
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight mb-2">Bienvenido</h1>
                <p class="text-slate-500 text-sm">Ingresa a tu portal inteligente de servicios fiscales.</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-50/80 backdrop-blur-sm text-red-600 p-4 rounded-xl mb-6 text-sm font-medium border border-red-100 flex items-center gap-3">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="auth.php?action=login" method="POST" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">Correo Electrónico</label>
                    <input type="email" id="email" name="email" required 
                           class="w-full px-4 py-3.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all bg-slate-50/50 hover:bg-slate-50"
                           placeholder="tu@correo.com">
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">Contraseña</label>
                    <input type="password" id="password" name="password" required 
                           class="w-full px-4 py-3.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all bg-slate-50/50 hover:bg-slate-50"
                           placeholder="••••••••">
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-3.5 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-slate-900/20 active:scale-[0.98]">
                        Ingresar al Portal
                    </button>
                </div>
            </form>
        </div>
        <p class="text-center mt-8 text-sm text-slate-400">
            Portal Asesoría Financiera &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
