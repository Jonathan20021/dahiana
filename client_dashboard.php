<?php
require_once 'config.php';
requireAuth('client');

$client_id = $_SESSION['user_id'];

// Fetch requests for this client
$stmt = $pdo->prepare("
    SELECT r.*, s.title, s.type 
    FROM requests r 
    JOIN services s ON r.service_id = s.id 
    WHERE r.client_id = ? 
    ORDER BY r.created_at DESC
");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();

$igualas = array_filter($requests, fn($r) => $r['type'] === 'iguala');
$puntuales = array_filter($requests, fn($r) => $r['type'] === 'puntual');

// Data for Personal Progress Chart
$clientStatusCounts = ['pendiente' => 0, 'en_proceso' => 0, 'en_revision' => 0, 'finalizado' => 0];
foreach ($requests as $r) {
    if (in_array($r['status'], ['completado', 'presentado'])) {
        $clientStatusCounts['finalizado']++;
    } else {
        $clientStatusCounts[$r['status']]++;
    }
}
$chartClientData = array_values($clientStatusCounts);

// Helper to calculate progress percentage for the timeline
function getProgressPercentage($status) {
    return match($status) {
        'pendiente' => '25%',
        'en_proceso' => '50%',
        'en_revision' => '75%',
        'completado', 'presentado' => '100%',
        default => '0%'
    };
}
function isStepActive($currentStatus, $stepStatus) {
    $levels = ['pendiente' => 1, 'en_proceso' => 2, 'en_revision' => 3, 'completado' => 4, 'presentado' => 4];
    $current = $levels[$currentStatus] ?? 0;
    $step = $levels[$stepStatus] ?? 0;
    return $current >= $step;
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Panel - Portal Asesoría</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full">
    <?php include 'components/header.php'; ?>
    <?php include 'components/sidebar.php'; ?>

    <main class="lg:pl-72 py-8">
        <div class="px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
            
            <!-- Welcome Banner -->
            <div class="rounded-3xl bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-10 shadow-lg shadow-blue-500/20 mb-8 relative overflow-hidden">
                <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMiIgY3k9IjIiIHI9IjEiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xKSIvPjwvc3ZnPg==')] opacity-30"></div>
                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div>
                        <h2 class="text-3xl font-bold tracking-tight text-white mb-2">
                            Hola, <?= explode(' ', $_SESSION['name'])[0] ?> 👋
                        </h2>
                        <p class="text-blue-100 text-lg">Este es tu espacio personal para dar seguimiento a tus servicios fiscales.</p>
                    </div>
                    <!-- Mini Chart in Banner -->
                    <div class="bg-white/10 backdrop-blur-md rounded-2xl p-4 border border-white/20 w-full md:w-64">
                        <h4 class="text-white text-xs font-bold uppercase tracking-wider mb-3 text-center">Mi Progreso General</h4>
                        <div class="relative h-32">
                            <canvas id="clientStatusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-8">
                
                <!-- Igualas Mensuales -->
                <section>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2.5 bg-white text-blue-600 rounded-xl shadow-sm border border-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">Igualas Mensuales Activas</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (empty($igualas)): ?>
                            <div class="col-span-full py-8 text-center bg-white rounded-3xl border border-slate-100 border-dashed">
                                <p class="text-slate-400">No hay igualas registradas para este período.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($igualas as $req): ?>
                            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 hover:shadow-md transition-all hover:border-blue-100 relative overflow-hidden group">
                                <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-blue-50 to-transparent rounded-bl-full -z-10 group-hover:scale-110 transition-transform"></div>
                                <div class="flex justify-between items-start mb-4">
                                    <h4 class="font-semibold text-slate-800 pr-4 leading-tight"><?= htmlspecialchars($req['title']) ?></h4>
                                    <?= getStatusBadge($req['status']) ?>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-slate-500 bg-slate-50 w-max px-3 py-1.5 rounded-lg border border-slate-100/50">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10" /></svg>
                                    Período: <span class="font-medium text-slate-700"><?= htmlspecialchars($req['period']) ?></span>
                                </div>
                                <a href="request_view.php?id=<?= $req['id'] ?>" class="mt-4 flex items-center gap-2 text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                    Abrir solicitud &rarr;
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Solicitudes Puntuales (Timeline) -->
                <section>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2.5 bg-white text-indigo-600 rounded-xl shadow-sm border border-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">Solicitudes y Trámites Puntuales</h3>
                    </div>

                    <div class="space-y-6">
                        <?php if (empty($puntuales)): ?>
                            <div class="py-12 text-center bg-white rounded-3xl border border-slate-100 border-dashed">
                                <p class="text-slate-400">No tienes solicitudes puntuales en curso.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($puntuales as $req): ?>
                            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
                                    <div>
                                        <h4 class="text-lg font-bold text-slate-900 mb-1"><?= htmlspecialchars($req['title']) ?></h4>
                                        <?php if($req['estimated_delivery_date']): ?>
                                        <p class="text-sm text-slate-500 bg-amber-50 text-amber-700 px-3 py-1 rounded-lg inline-flex items-center gap-1.5 border border-amber-100/50">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            Entrega estimada: <strong><?= date('d/m/Y', strtotime($req['estimated_delivery_date'])) ?></strong>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <?= getStatusBadge($req['status']) ?>
                                        <a href="request_view.php?id=<?= $req['id'] ?>" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition-all">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                            Abrir
                                        </a>
                                    </div>
                                </div>

                                <!-- Progress Timeline -->
                                <div class="relative pt-2">
                                    <div class="overflow-hidden h-2 mb-6 text-xs flex rounded-full bg-slate-100">
                                        <div style="width: <?= getProgressPercentage($req['status']) ?>" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500 transition-all duration-500"></div>
                                    </div>
                                    <div class="flex justify-between w-full text-xs font-medium text-slate-400">
                                        <div class="text-left w-1/4 <?= isStepActive($req['status'], 'pendiente') ? 'text-indigo-600' : '' ?>">Pendiente</div>
                                        <div class="text-center w-1/4 <?= isStepActive($req['status'], 'en_proceso') ? 'text-indigo-600' : '' ?>">En Proceso</div>
                                        <div class="text-center w-1/4 <?= isStepActive($req['status'], 'en_revision') ? 'text-indigo-600' : '' ?>">En Revisión</div>
                                        <div class="text-right w-1/4 <?= isStepActive($req['status'], 'completado') ? 'text-indigo-600' : '' ?>">Entregado</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('clientStatusChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Pendiente', 'En Proceso', 'En Revisión', 'Finalizado'],
                datasets: [{
                    data: <?= json_encode($chartClientData) ?>,
                    backgroundColor: ['#fca5a5', '#fcd34d', '#93c5fd', '#86efac'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                cutout: '70%'
            }
        });
    </script>
                
                <!-- Igualas Mensuales -->
                <section>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2.5 bg-white text-blue-600 rounded-xl shadow-sm border border-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">Igualas Mensuales Activas</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (empty($igualas)): ?>
                            <div class="col-span-full py-8 text-center bg-white rounded-3xl border border-slate-100 border-dashed">
                                <p class="text-slate-400">No hay igualas registradas para este período.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($igualas as $req): ?>
                            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 hover:shadow-md transition-all hover:border-blue-100 relative overflow-hidden group">
                                <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-blue-50 to-transparent rounded-bl-full -z-10 group-hover:scale-110 transition-transform"></div>
                                <div class="flex justify-between items-start mb-4">
                                    <h4 class="font-semibold text-slate-800 pr-4 leading-tight"><?= htmlspecialchars($req['title']) ?></h4>
                                    <?= getStatusBadge($req['status']) ?>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-slate-500 bg-slate-50 w-max px-3 py-1.5 rounded-lg border border-slate-100/50">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10" /></svg>
                                    Período: <span class="font-medium text-slate-700"><?= htmlspecialchars($req['period']) ?></span>
                                </div>
                                <a href="request_view.php?id=<?= $req['id'] ?>" class="mt-4 flex items-center gap-2 text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                    Abrir solicitud &rarr;
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Solicitudes Puntuales (Timeline) -->
                <section>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2.5 bg-white text-indigo-600 rounded-xl shadow-sm border border-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900">Solicitudes y Trámites Puntuales</h3>
                    </div>

                    <div class="space-y-6">
                        <?php if (empty($puntuales)): ?>
                            <div class="py-12 text-center bg-white rounded-3xl border border-slate-100 border-dashed">
                                <p class="text-slate-400">No tienes solicitudes puntuales en curso.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($puntuales as $req): ?>
                            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
                                    <div>
                                        <h4 class="text-lg font-bold text-slate-900 mb-1"><?= htmlspecialchars($req['title']) ?></h4>
                                        <?php if($req['estimated_delivery_date']): ?>
                                        <p class="text-sm text-slate-500 bg-amber-50 text-amber-700 px-3 py-1 rounded-lg inline-flex items-center gap-1.5 border border-amber-100/50">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            Entrega estimada: <strong><?= date('d/m/Y', strtotime($req['estimated_delivery_date'])) ?></strong>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <?= getStatusBadge($req['status']) ?>
                                        <a href="request_view.php?id=<?= $req['id'] ?>" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition-all">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                            Abrir
                                        </a>
                                    </div>
                                </div>

                                <!-- Progress Timeline -->
                                <div class="relative pt-2">
                                    <div class="overflow-hidden h-2 mb-6 text-xs flex rounded-full bg-slate-100">
                                        <div style="width: <?= getProgressPercentage($req['status']) ?>" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500 transition-all duration-500"></div>
                                    </div>
                                    <div class="flex justify-between w-full text-xs font-medium text-slate-400">
                                        <div class="text-left w-1/4 <?= isStepActive($req['status'], 'pendiente') ? 'text-indigo-600' : '' ?>">Pendiente</div>
                                        <div class="text-center w-1/4 <?= isStepActive($req['status'], 'en_proceso') ? 'text-indigo-600' : '' ?>">En Proceso</div>
                                        <div class="text-center w-1/4 <?= isStepActive($req['status'], 'en_revision') ? 'text-indigo-600' : '' ?>">En Revisión</div>
                                        <div class="text-right w-1/4 <?= isStepActive($req['status'], 'completado') ? 'text-indigo-600' : '' ?>">Entregado</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
        </div>
    </main>
</body>
</html>
