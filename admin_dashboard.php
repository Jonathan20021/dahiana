<?php
require_once 'config.php';
requireAuth('admin');

// Handle adding client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($name && $email && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, password_hash) VALUES (?, ?, ?, 'client', ?)");
        try {
            $stmt->execute([$name, $email, $phone, $hash]);
            $success = "Cliente agregado exitosamente.";
        } catch (PDOException $e) {
            $error = "Error al agregar el cliente. Es posible que el correo ya exista.";
        }
    }
}

// Fetch 360 Metrics
$totalClients = $pdo->query("
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pendiente'")->fetchColumn();
$inProcessRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='en_proceso'")->fetchColumn();
$completedRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='completado' OR status='presentado'")->fetchColumn();

// Data for Status Distribution Chart
$statusCounts = $pdo->query("SELECT status, COUNT(*) as count FROM requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$allStatuses = ['pendiente', 'en_proceso', 'en_revision', 'presentado', 'completado'];
$chartStatusData = [];
foreach ($allStatuses as $s) {
    $chartStatusData[] = $statusCounts[$s] ?? 0;
}

// Data for Client Growth Chart (Last 6 months)
$growthData = $pdo->query("
    SELECT DATE_FORMAT(u.created_at, '%Y-%m') as month, COUNT(*) as count
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
      AND u.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month 
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chartMonths = [];
$chartGrowthValues = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chartMonths[] = date('M', strtotime("-$i months"));
    $chartGrowthValues[] = $growthData[$m] ?? 0;
}

// Fetch all client-side users
$stmt = $pdo->query("
    SELECT u.*
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
    ORDER BY u.created_at DESC
");
$clients = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel 360 - Portal Asesoría</title>
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
        <div class="px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
            
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Vista 360</h1>
                    <p class="mt-1 text-sm text-slate-500">Resumen y administración general de tu cartera de clientes.</p>
                </div>
                <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex items-center gap-3">
                    <a href="admin_users.php" class="inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-200 hover:bg-slate-50 transition-all hover:-translate-y-0.5">
                        Gestionar usuarios
                    </a>
                    <button type="button" onclick="document.getElementById('addClientModal').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 transition-all hover:-translate-y-0.5">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        Nuevo Cliente
                    </button>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
                <div class="relative overflow-hidden rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100">
                    <dt>
                        <div class="absolute rounded-xl bg-blue-50 p-3">
                            <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <p class="ml-16 truncate text-sm font-medium text-slate-500">Total Clientes</p>
                    </dt>
                    <dd class="ml-16 flex items-baseline pb-1 sm:pb-2">
                        <p class="text-2xl font-semibold text-slate-900"><?= $totalClients ?></p>
                    </dd>
                </div>
                
                <div class="relative overflow-hidden rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100">
                    <dt>
                        <div class="absolute rounded-xl bg-red-50 p-3">
                            <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        </div>
                        <p class="ml-16 truncate text-sm font-medium text-slate-500">Tareas Pendientes</p>
                    </dt>
                    <dd class="ml-16 flex items-baseline pb-1 sm:pb-2">
                        <p class="text-2xl font-semibold text-slate-900"><?= $pendingRequests ?></p>
                    </dd>
                </div>

                <div class="relative overflow-hidden rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100">
                    <dt>
                        <div class="absolute rounded-xl bg-yellow-50 p-3">
                            <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                        </div>
                        <p class="ml-16 truncate text-sm font-medium text-slate-500">En Proceso</p>
                    </dt>
                    <dd class="ml-16 flex items-baseline pb-1 sm:pb-2">
                        <p class="text-2xl font-semibold text-slate-900"><?= $inProcessRequests ?></p>
                    </dd>
                </div>

                <div class="relative overflow-hidden rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100">
                    <dt>
                        <div class="absolute rounded-xl bg-green-50 p-3">
                            <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <p class="ml-16 truncate text-sm font-medium text-slate-500">Presentadas/Completadas</p>
                    </dt>
                    <dd class="ml-16 flex items-baseline pb-1 sm:pb-2">
                        <p class="text-2xl font-semibold text-slate-900"><?= $completedRequests ?></p>
                    </dd>
                </div>
            </dl>

            <?php if (isset($success)): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100">
                <div class="flex">
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
            <div class="mb-6 rounded-2xl bg-red-50 p-4 border border-red-100">
                <div class="flex">
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Status Distribution -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900 mb-6">Distribución de Solicitudes</h3>
                    <div class="relative h-64">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                <!-- Client Growth -->
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900 mb-6">Nuevos Clientes (6 Meses)</h3>
                    <div class="relative h-64">
                        <canvas id="growthChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Clients List -->
            <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                    <h3 class="text-base font-semibold text-slate-900">Directorio de Clientes</h3>
                </div>
                <div class="flow-root">
                    <ul role="list" class="divide-y divide-slate-100">
                        <?php foreach ($clients as $client): ?>
                        <li class="relative flex justify-between gap-x-6 px-6 py-5 hover:bg-slate-50 transition-colors">
                            <div class="flex min-w-0 gap-x-4 items-center">
                                <div class="h-12 w-12 flex-none rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center">
                                    <span class="text-lg font-semibold text-slate-500"><?= substr(strtoupper($client['name']), 0, 1) ?></span>
                                </div>
                                <div class="min-w-0 flex-auto">
                                    <p class="text-sm font-semibold leading-6 text-slate-900">
                                        <a href="client_details.php?id=<?= $client['id'] ?>">
                                            <span class="absolute inset-x-0 -top-px bottom-0"></span>
                                            <?= htmlspecialchars($client['name']) ?>
                                        </a>
                                    </p>
                                    <p class="mt-1 flex text-xs leading-5 text-slate-500 gap-x-2">
                                        <span><?= htmlspecialchars($client['email']) ?></span>
                                        <span class="text-slate-300">&bull;</span>
                                        <span><?= htmlspecialchars($client['phone'] ?: 'Sin Teléfono') ?></span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-x-4">
                                <div class="hidden sm:flex sm:flex-col sm:items-end">
                                    <p class="text-sm leading-6 text-slate-900">Registrado</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        <?= date('d/m/Y', strtotime($client['created_at'])) ?>
                                    </p>
                                </div>
                                <svg class="h-5 w-5 flex-none text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Common chart options
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, font: { family: 'Outfit', size: 12 } } }
            }
        };

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pendiente', 'En Proceso', 'En Revisión', 'Presentado', 'Completado'],
                datasets: [{
                    data: <?= json_encode($chartStatusData) ?>,
                    backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#10b981', '#059669'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                ...chartOptions,
                cutout: '70%'
            }
        });

        // Client Growth Chart
        const growthCtx = document.getElementById('growthChart').getContext('2d');
        new Chart(growthCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartMonths) ?>,
                datasets: [{
                    label: 'Nuevos Clientes',
                    data: <?= json_encode($chartGrowthValues) ?>,
                    backgroundColor: '#0f172a',
                    borderRadius: 8,
                    maxBarThickness: 30
                }]
            },
            options: {
                ...chartOptions,
                plugins: { ...chartOptions.plugins, legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { display: false }, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>

    <!-- Modal agregar cliente -->
    <div id="addClientModal" class="relative z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="relative transform overflow-hidden rounded-3xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <form action="admin_dashboard.php" method="POST">
                        <input type="hidden" name="action" value="add_client">
                        <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                                    </svg>
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                                    <h3 class="text-lg font-semibold leading-6 text-slate-900" id="modal-title">Agregar Nuevo Cliente</h3>
                                    <div class="mt-6 space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium leading-6 text-slate-900">Nombre Completo</label>
                                            <input type="text" name="name" required class="mt-2 block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm sm:leading-6">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium leading-6 text-slate-900">Correo Electrónico</label>
                                            <input type="email" name="email" required class="mt-2 block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm sm:leading-6">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium leading-6 text-slate-900">Teléfono (WhatsApp)</label>
                                            <input type="text" name="phone" placeholder="+18090000000" class="mt-2 block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm sm:leading-6">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium leading-6 text-slate-900">Contraseña Inicial</label>
                                            <input type="text" name="password" required class="mt-2 block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm sm:leading-6">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-slate-50 px-4 py-4 sm:flex sm:flex-row-reverse sm:px-6 rounded-b-3xl">
                            <button type="submit" class="inline-flex w-full justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 sm:ml-3 sm:w-auto transition-all">Guardar Cliente</button>
                            <button type="button" onclick="document.getElementById('addClientModal').classList.add('hidden')" class="mt-3 inline-flex w-full justify-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto transition-all">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
