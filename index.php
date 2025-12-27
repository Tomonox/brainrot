<?php
session_start();

// =========================================================
// 🔐 CONFIGURATION & SÉCURITÉ (SUPABASE AUTH)
// =========================================================

$supabase_base_url = "https://glqreyurigjcqkmvftme.supabase.co/rest/v1";
$api_key = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdscXJleXVyaWdqY3FrbXZmdG1lIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjYwMTIyNDMsImV4cCI6MjA4MTU4ODI0M30.Ia5zV8zwT5SqWrFO1161nwFaXKVI0uOasseKTQVMZgo";

// 1. Déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 2. Traitement du Login
$login_error = "";
if (isset($_POST['login_btn'])) {
    $u_name = trim($_POST['username']);
    $u_pass = trim($_POST['password']);

    // On récupère l'user ET son rôle
    $auth_url = $supabase_base_url . "/users?name=eq." . urlencode($u_name) . "&select=*";
    
    $opts_auth = [
        "http" => [
            "method" => "GET",
            "header" => "apikey: " . $api_key . "\r\n" .
                        "Authorization: Bearer " . $api_key . "\r\n"
        ]
    ];
    
    $context_auth = stream_context_create($opts_auth);
    $result_auth = @file_get_contents($auth_url, false, $context_auth);
    
    if ($result_auth) {
        $data_user = json_decode($result_auth, true);
        
        if (!empty($data_user) && isset($data_user[0])) {
            if ($data_user[0]['password'] === $u_pass) {
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_name'] = $data_user[0]['name'];
                $_SESSION['user_role'] = $data_user[0]['role']; 
                header("Location: index.php");
                exit;
            } else {
                $login_error = "Mot de passe incorrect.";
            }
        } else {
            $login_error = "Utilisateur inconnu.";
        }
    } else {
        $login_error = "Erreur de connexion serveur.";
    }
}

$is_logged_in = isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true;
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'visiteur';

// =========================================================
// 🌐 RÉCUPÉRATION DATA (Uniquement si connecté)
// =========================================================

$toutes_les_ventes = [];

if ($is_logged_in) {
    $ventes_url = $supabase_base_url . "/ventes_brainrot?select=*&order=date_vente.desc";
    $opts_data = [
        "http" => [ "method" => "GET", "header" => "apikey: " . $api_key . "\r\n" . "Authorization: Bearer " . $api_key . "\r\n" ]
    ];
    $context_data = stream_context_create($opts_data);
    $response = @file_get_contents($ventes_url, false, $context_data);
    if ($response !== FALSE) { $toutes_les_ventes = json_decode($response, true); }
}

// =========================================================
// 🧠 LOGIQUE CALCULS
// =========================================================

function traduire_mois($date_format) {
    $mois_fr = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    return strtr($date_format, $mois_fr);
}

$historique_taux = [];
if ($is_logged_in) {
    try {
        $arrContextOptions=array("ssl"=>array("verify_peer"=>false,"verify_peer_name"=>false));  
        $api_frank = "https://api.frankfurter.app/2023-01-01..?from=USD&to=EUR";
        $json_taux = @file_get_contents($api_frank, false, stream_context_create($arrContextOptions));
        if ($json_taux) { $historique_taux = json_decode($json_taux, true)['rates'] ?? []; }
    } catch (Exception $e) {}
}

function get_taux_reel($date_vente, $historique_taux) {
    $date_obj = new DateTime($date_vente);
    $date_str = $date_obj->format('Y-m-d');
    if (isset($historique_taux[$date_str]['EUR'])) return $historique_taux[$date_str]['EUR'];
    for ($i = 1; $i <= 5; $i++) {
        $date_obj->modify('-1 day');
        $date_prev = $date_obj->format('Y-m-d');
        if (isset($historique_taux[$date_prev]['EUR'])) return $historique_taux[$date_prev]['EUR'];
    }
    return 0.95; 
}

$stats_mensuelles = []; $retraits_par_mois = []; $stats_hebdo = [];
$total_ca_usd = 0; $total_ca_eur = 0; $total_retraits = 0;
$max_win = 0; $total_amounts = 0; 
$mois_actuel_key = date('Y-m'); $jour_actuel = date('d');

if (!empty($toutes_les_ventes)) {
    foreach ($toutes_les_ventes as $vente) {
        $date = $vente['date_vente'];
        $montant_usd = $vente['montant'];
        $taux_du_jour = get_taux_reel($date, $historique_taux);
        $montant_eur = $montant_usd * $taux_du_jour;
        $vente['montant_eur'] = $montant_eur;
        $vente['taux_appliq'] = $taux_du_jour;
        
        $mois_key = date('Y-m', strtotime($date));
        $semaine_key = date('o-W', strtotime($date)); 
        
        if (!isset($stats_mensuelles[$mois_key])) $stats_mensuelles[$mois_key] = ['mois'=>$mois_key, 'nb_retraits'=>0, 'ca_mensuel_usd'=>0, 'ca_mensuel_eur'=>0];
        $stats_mensuelles[$mois_key]['nb_retraits']++;
        $stats_mensuelles[$mois_key]['ca_mensuel_usd'] += $montant_usd;
        $stats_mensuelles[$mois_key]['ca_mensuel_eur'] += $montant_eur;
        
        if ($date >= date('Y-m-d', strtotime('-8 weeks'))) {
            if (!isset($stats_hebdo[$semaine_key])) $stats_hebdo[$semaine_key] = ['usd'=>0, 'start_date'=>$date];
            $stats_hebdo[$semaine_key]['usd'] += $montant_usd;
            $stats_hebdo[$semaine_key]['start_date'] = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        }

        $retraits_par_mois[$mois_key][] = $vente;
        $total_ca_usd += $montant_usd; $total_ca_eur += $montant_eur; $total_retraits++;
        if ($montant_usd > $max_win) $max_win = $montant_usd;
        $total_amounts += $montant_usd;
    }
}

$avg_win = $total_retraits > 0 ? $total_amounts / $total_retraits : 0;
$stat_actuelle = $stats_mensuelles[$mois_actuel_key] ?? ['nb_retraits'=>0, 'ca_mensuel_usd'=>0, 'ca_mensuel_eur'=>0];
$ca_actuel_usd = $stat_actuelle['ca_mensuel_usd'];
$mois_prec_key = date('Y-m', strtotime('first day of last month'));
$ca_precedent_pmtd_usd = 0;
if (isset($retraits_par_mois[$mois_prec_key])) {
    foreach ($retraits_par_mois[$mois_prec_key] as $v) {
        if (date('d', strtotime($v['date_vente'])) <= $jour_actuel) $ca_precedent_pmtd_usd += $v['montant'];
    }
}

$evolution_pct = 0; $symbole_evol = '➖'; $color_evol = 'text-muted';
if ($ca_precedent_pmtd_usd > 0) {
    $evolution_pct = round((($ca_actuel_usd - $ca_precedent_pmtd_usd) / $ca_precedent_pmtd_usd) * 100, 1);
    if ($evolution_pct > 0) { $symbole_evol = '▲'; $color_evol = 'text-success'; }
    elseif ($evolution_pct < 0) { $symbole_evol = '▼'; $color_evol = 'text-danger'; }
}

$nb_jours_mois = date('t');
$jours_passes = max(1, (int)$jour_actuel);
$projection_usd = ($jours_passes > 0) ? ($ca_actuel_usd / $jours_passes) * $nb_jours_mois : 0;

ksort($stats_hebdo);
$labels_hebdo = []; $data_hebdo = [];
foreach ($stats_hebdo as $sem => $data) { $labels_hebdo[] = "Sem. " . substr($sem, 5); $data_hebdo[] = $data['usd']; }

function getCumulArray($transactions, $target, $limit) {
    $daily = array_fill(1, 31, 0);
    if (isset($transactions[$target])) { foreach ($transactions[$target] as $t) $daily[(int)date('d', strtotime($t['date_vente']))] += $t['montant']; }
    $cumul = []; $run = 0;
    for ($i=1; $i<=31; $i++) { if ($i > $limit) break; $run += $daily[$i]; $cumul[] = $run; }
    return $cumul;
}
$data_cumul_actuel = getCumulArray($retraits_par_mois, $mois_actuel_key, $jour_actuel);
$data_cumul_prev = getCumulArray($retraits_par_mois, $mois_prec_key, 31);
krsort($stats_mensuelles);
$stats_mensuelles = array_slice($stats_mensuelles, 0, 12);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>🧠 Steal A Brainrot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* DESIGN MOBILE FIRST & APP STYLE */
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
            background-color: #f5f7fa; 
            color: #2e3e5c; 
            padding-bottom: 40px;
        }
        
        .header-app {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .header-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #4a6fa5;
            margin: 0;
        }

        .card-custom {
            border: none;
            border-radius: 15px;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 20px;
            padding: 20px;
        }

        /* BADGES STATS ROW */
        .stat-badge {
            background: white;
            border-radius: 12px;
            border: 1px solid #eef2f6;
            padding: 10px 5px;
            text-align: center;
            height: 100%;
        }
        .stat-badge i { font-size: 1.2rem; margin-bottom: 5px; display: block; }
        .stat-badge .val { font-weight: 800; font-size: 0.95rem; color: #2e3e5c; display: block; }
        .stat-badge .lbl { font-size: 0.7rem; color: #8898aa; text-transform: uppercase; letter-spacing: 0.5px; }

        /* BUTTON */
        .btn-add {
            background: #4a6fa5;
            color: white;
            border-radius: 12px;
            font-weight: 700;
            padding: 15px;
            width: 100%;
            border: none;
            box-shadow: 0 4px 12px rgba(74, 111, 165, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn-add:hover { background: #3b5d8a; color: white; }

        /* KPI PRINCIPAUX */
        .kpi-main-val { font-size: 1.8rem; font-weight: 800; color: #4a6fa5; }
        .kpi-sub { font-size: 0.8rem; color: #8898aa; }

        /* CHARTS */
        .chart-box { position: relative; height: 250px; width: 100%; }

        /* TABS ET TABLES */
        .monthly-tabs-nav { 
            display: flex; 
            overflow-x: auto; 
            padding-bottom: 10px; 
            gap: 10px; 
            scrollbar-width: none; 
        }
        .monthly-tab-btn {
            background: #e7effa;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            color: #4a6fa5;
            font-weight: 600;
            white-space: nowrap;
            font-size: 0.9rem;
        }
        .monthly-tab-btn.active { background: #4a6fa5; color: white; }
        
        /* TABLE CLEAN */
        .table-custom th { 
            background: #f8f9fa; 
            color: #8898aa; 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            border: none; 
            padding: 12px;
        }
        .table-custom td { 
            padding: 12px; 
            vertical-align: middle; 
            border-bottom: 1px solid #f0f0f0; 
            font-size: 0.9rem;
        }
        .monthly-content { display: none; }
        .monthly-content.active { display: block; animation: fadeIn 0.3s; }

        /* LOGIN */
        .login-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(245, 247, 250, 0.95); z-index: 9999; 
            display: flex; align-items: center; justify-content: center; 
        }
        .login-card { width: 90%; max-width: 350px; text-align: center; }
        .login-input { 
            background: white; border: 1px solid #e1e4e8; 
            padding: 15px; border-radius: 12px; width: 100%; 
            margin-bottom: 10px; text-align: center; font-size: 1rem; 
        }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<?php if (!$is_logged_in): ?>
<div class="login-overlay">
    <div class="login-card">
        <div style="font-size: 3.5rem; margin-bottom: 10px;">🧠</div>
        <h2 style="color: #4a6fa5; font-weight: 800; margin-bottom: 5px;">Brainrot</h2>
        <p class="text-muted small mb-4">Accès sécurisé</p>
        <form method="POST">
            <input type="text" name="username" class="login-input" placeholder="Utilisateur" required autofocus autocomplete="off">
            <input type="password" name="password" class="login-input" placeholder="Mot de passe" required>
            <?php if ($login_error): ?><div class="text-danger small mb-3"><?= $login_error ?></div><?php endif; ?>
            <button type="submit" name="login_btn" class="btn-add mt-2">Déverrouiller</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="header-app d-flex justify-content-between align-items-center">
    <h1 class="header-title">🧠 Steal A Brainrot</h1>
    <?php if ($is_logged_in): ?>
        <a href="?logout=1" class="text-danger" style="font-size: 1.2rem;"><i class="fas fa-power-off"></i></a>
    <?php endif; ?>
</div>

<div class="container">
    
    <div class="text-center mb-4">
        <span class="badge bg-light text-dark border">
            <i class="fas fa-user-circle me-1"></i> <?= isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Invité' ?>
        </span>
        <span class="badge bg-secondary ms-1"><?= ucfirst($user_role) ?></span>
    </div>

    <?php if($user_role === 'admin'): ?>
        <a href="add_retrait.php" class="btn-add shadow-sm">
            <i class="fas fa-plus-circle"></i> AJOUTER UN RETRAIT
        </a>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="stat-badge">
                <i class="fas fa-trophy text-warning"></i>
                <span class="val"><?= number_format($max_win, 0, ',', ' ') ?> $</span>
                <span class="lbl">Record</span>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-badge">
                <i class="fas fa-chart-line text-info"></i>
                <span class="val"><?= number_format($avg_win, 0, ',', ' ') ?> $</span>
                <span class="lbl">Moyenne</span>
            </div>
        </div>
        <div class="col-4">
            <div class="stat-badge">
                <i class="fas fa-bullseye text-danger"></i>
                <span class="val"><?= number_format($projection_usd, 0, ',', ' ') ?> $</span>
                <span class="lbl">Project.</span>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6">
            <div class="card-custom text-center h-100 py-3">
                <div class="kpi-sub">💸 CA USD Mois</div>
                <div class="kpi-main-val"><?= number_format($stat_actuelle['ca_mensuel_usd'], 0, ',', ' ') ?> $</div>
                <div class="kpi-sub"><?= traduire_mois(date('F Y')) ?></div>
            </div>
        </div>
        <div class="col-6">
            <div class="card-custom text-center h-100 py-3">
                <div class="kpi-sub">📊 Évolution (J1-<?= (int)$jour_actuel ?>)</div>
                <div class="kpi-main-val <?= $evolution_pct >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size: 1.6rem;">
                    <?= $symbole_evol ?> <?= number_format(abs($evolution_pct), 1) ?>%
                </div>
                <div class="kpi-sub">vs mois dernier</div>
            </div>
        </div>
        <div class="col-12">
            <div class="card-custom text-center py-3">
                <div class="kpi-sub">📈 CA Total (12 mois)</div>
                <div class="kpi-main-val" style="color: #2e3e5c;"><?= number_format($total_ca_usd, 2, ',', ' ') ?> $</div>
                <div class="kpi-sub">≈ <?= number_format($total_ca_eur, 2, ',', ' ') ?> €</div>
            </div>
        </div>
    </div>

    <div class="card-custom">
        <h6 class="fw-bold mb-3" style="color: #4a6fa5;">🏃 La Course (Cumulé USD)</h6>
        <div class="chart-box">
            <canvas id="cumulativeChart"></canvas>
        </div>
    </div>

    <div class="card-custom">
        <h6 class="fw-bold mb-3" style="color: #4a6fa5;">📈 CA Hebdomadaire</h6>
        <div class="chart-box">
            <canvas id="weeklyChart"></canvas>
        </div>
    </div>
    
    <div class="card-custom">
        <h6 class="fw-bold mb-3" style="color: #4a6fa5;">🗓️ Historique</h6>
        <div class="monthly-tabs-nav" id="monthly-tabs">
            <?php $is_first = true; foreach ($retraits_par_mois as $mois_key => $retraits): $active_class = $is_first ? 'active' : ''; ?>
                <button class="monthly-tab-btn <?= $active_class ?>" data-target="<?= $mois_key ?>"><?= traduire_mois(date('F Y', strtotime($mois_key . '-01'))) ?></button>
            <?php $is_first = false; endforeach; ?>
        </div>
        
        <div id="monthly-content-container" class="mt-2">
            <?php $is_first = true; foreach ($retraits_par_mois as $mois_key => $retraits): $active_class = $is_first ? 'active' : ''; ?>
            <div id="content-<?= $mois_key ?>" class="monthly-content <?= $active_class ?>">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead><tr><th>Montant</th><th class="text-end">Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($retraits as $retrait): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary"><?= number_format($retrait['montant'], 2) ?> $</span><br>
                                    <small class="text-muted">≈ <?= number_format($retrait['montant_eur'], 2) ?> €</small>
                                </td>
                                <td class="text-end text-muted"><?= date('d/m', strtotime($retrait['date_vente'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php $is_first = false; endforeach; ?>
        </div>
    </div>

    <div class="card-custom p-0 overflow-hidden">
        <div class="p-3 border-bottom bg-light">
             <h6 class="fw-bold m-0" style="color: #4a6fa5;">📑 Récapitulatif Gains</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-custom mb-0 table-striped">
                <thead><tr><th>Mois</th><th class="text-center">#</th><th class="text-end">Total USD</th></tr></thead>
                <tbody>
                    <?php foreach ($stats_mensuelles as $stat): ?>
                    <tr>
                        <td class="fw-bold"><?= ucfirst(traduire_mois(date('M y', strtotime($stat['mois'] . '-01')))) ?></td>
                        <td class="text-center"><?= $stat['nb_retraits'] ?></td>
                        <td class="text-end fw-bold text-primary"><?= number_format($stat['ca_mensuel_usd'], 0) ?> $</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-primary text-white">
                        <td class="fw-bold text-white">TOTAL</td>
                        <td class="text-center text-white"><?= $total_retraits ?></td>
                        <td class="text-end fw-bold text-white"><?= number_format($total_ca_usd, 0) ?> $</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart Cumul
    const ctxCumul = document.getElementById('cumulativeChart').getContext('2d');
    new Chart(ctxCumul, {
        type: 'line',
        data: {
            labels: Array.from({length: 31}, (_, i) => i + 1),
            datasets: [
                { label: 'Ce Mois', data: <?= json_encode($data_cumul_actuel) ?>, borderColor: '#4a6fa5', backgroundColor: 'rgba(74, 111, 165, 0.1)', borderWidth: 3, pointRadius: 0, borderCapStyle: 'round', fill: true, tension: 0.4 },
                { label: 'Mois Dernier', data: <?= json_encode($data_cumul_prev) ?>, borderColor: '#cbd2d9', backgroundColor: 'transparent', borderDash: [5, 5], borderWidth: 2, pointRadius: 0, fill: false, tension: 0.4 }
            ]
        },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            interaction: { mode: 'index', intersect: false }, 
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#2e3e5c', titleColor: '#fff', bodyColor: '#fff', displayColors: false, callbacks: { label: c => c.formattedValue + ' $' } } }, 
            scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f0f0f0' }, ticks: { font: { size: 10 } } } } 
        }
    });

    // Chart Hebdo
    const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
    new Chart(weeklyCtx, {
        type: 'bar',
        data: { labels: <?= json_encode($labels_hebdo) ?>, datasets: [{ label: 'USD', data: <?= json_encode($data_hebdo) ?>, backgroundColor: '#4a6fa5', borderRadius: 4 }] },
        options: { 
            responsive: true, maintainAspectRatio: false, 
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#2e3e5c', callbacks: { label: c => c.formattedValue + ' $' } } },
            scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } } }, y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f0f0f0' } } } 
        }
    });

    // Tabs Logic
    const tabButtons = document.querySelectorAll('.monthly-tab-btn');
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            tabButtons.forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.monthly-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            const target = document.getElementById('content-' + targetId);
            if(target) target.classList.add('active');
        });
    });
    if (tabButtons.length > 0) tabButtons[0].click();
});
</script>
</body>
</html>