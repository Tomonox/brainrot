<?php
session_start();

// =========================================================
// 🔐 CONFIGURATION & SÉCURITÉ
// =========================================================

$supabase_base_url = "https://glqreyurigjcqkmvftme.supabase.co/rest/v1";
$api_key = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdscXJleXVyaWdqY3FrbXZmdG1lIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjYwMTIyNDMsImV4cCI6MjA4MTU4ODI0M30.Ia5zV8zwT5SqWrFO1161nwFaXKVI0uOasseKTQVMZgo";

// 1. Déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 2. Login
$login_error = "";
if (isset($_POST['login_btn'])) {
    $u_name = trim($_POST['username']);
    $u_pass = trim($_POST['password']);
    
    // Vérification user
    $auth_url = $supabase_base_url . "/users?name=eq." . urlencode($u_name) . "&select=*";
    $opts_auth = ["http" => ["method" => "GET", "header" => "apikey: $api_key\r\nAuthorization: Bearer $api_key\r\n"]];
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
            } else { $login_error = "Mot de passe incorrect."; }
        } else { $login_error = "Utilisateur inconnu."; }
    } else { $login_error = "Erreur connexion."; }
}

$is_logged_in = isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true;
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'visiteur';

// =========================================================
// 🌐 DATA SUPABASE
// =========================================================
$toutes_les_ventes = [];
if ($is_logged_in) {
    $ventes_url = $supabase_base_url . "/ventes_brainrot?select=*&order=date_vente.desc";
    $opts_data = ["http" => ["method" => "GET", "header" => "apikey: $api_key\r\nAuthorization: Bearer $api_key\r\n"]];
    $context_data = stream_context_create($opts_data);
    $res = @file_get_contents($ventes_url, false, $context_data);
    if ($res !== FALSE) { $toutes_les_ventes = json_decode($res, true); }
}

// =========================================================
// 🧠 CALCULS & PHP (Identique)
// =========================================================
function traduire_mois($d) {
    $m = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    return strtr($d, $m);
}

$historique_taux = [];
if ($is_logged_in) {
    try {
        $ctx = stream_context_create(["ssl"=>["verify_peer"=>false,"verify_peer_name"=>false]]);
        $json_taux = @file_get_contents("https://api.frankfurter.app/2023-01-01..?from=USD&to=EUR", false, $ctx);
        if ($json_taux) { $historique_taux = json_decode($json_taux, true)['rates'] ?? []; }
    } catch (Exception $e) {}
}

function get_taux_reel($d, $h) {
    $do = new DateTime($d); $ds = $do->format('Y-m-d');
    if (isset($h[$ds]['EUR'])) return $h[$ds]['EUR'];
    for ($i=1; $i<=5; $i++) { $do->modify('-1 day'); $dp = $do->format('Y-m-d'); if (isset($h[$dp]['EUR'])) return $h[$dp]['EUR']; }
    return 0.95; 
}

$stats_mensuelles = []; $retraits_par_mois = []; $stats_hebdo = [];
$total_ca_usd = 0; $total_ca_eur = 0; $total_retraits = 0;
$max_win = 0; $total_amounts = 0; 
$mois_actuel_key = date('Y-m'); $jour_actuel = date('d');

if (!empty($toutes_les_ventes)) {
    foreach ($toutes_les_ventes as $v) {
        $date = $v['date_vente']; $musd = $v['montant'];
        $taux = get_taux_reel($date, $historique_taux);
        $meur = $musd * $taux; $v['montant_eur'] = $meur; $v['taux_appliq'] = $taux;
        
        $mkey = date('Y-m', strtotime($date)); $wkey = date('o-W', strtotime($date));
        
        if (!isset($stats_mensuelles[$mkey])) $stats_mensuelles[$mkey] = ['mois'=>$mkey, 'nb_retraits'=>0, 'ca_mensuel_usd'=>0, 'ca_mensuel_eur'=>0];
        $stats_mensuelles[$mkey]['nb_retraits']++;
        $stats_mensuelles[$mkey]['ca_mensuel_usd'] += $musd;
        $stats_mensuelles[$mkey]['ca_mensuel_eur'] += $meur;
        
        if ($date >= date('Y-m-d', strtotime('-8 weeks'))) {
            if (!isset($stats_hebdo[$wkey])) $stats_hebdo[$wkey] = ['usd'=>0, 'start_date'=>$date];
            $stats_hebdo[$wkey]['usd'] += $musd;
        }

        $retraits_par_mois[$mkey][] = $v;
        $total_ca_usd += $musd; $total_ca_eur += $meur; $total_retraits++;
        if ($musd > $max_win) $max_win = $musd; $total_amounts += $musd;
    }
}

$avg_win = $total_retraits > 0 ? $total_amounts / $total_retraits : 0;
$stat_actuelle = $stats_mensuelles[$mois_actuel_key] ?? ['nb_retraits'=>0, 'ca_mensuel_usd'=>0, 'ca_mensuel_eur'=>0];
$ca_actuel_usd = $stat_actuelle['ca_mensuel_usd'];
$mois_prec_key = date('Y-m', strtotime('first day of last month'));
$ca_precedent_pmtd_usd = 0;
if (isset($retraits_par_mois[$mois_prec_key])) {
    foreach ($retraits_par_mois[$mois_prec_key] as $v) if (date('d', strtotime($v['date_vente'])) <= $jour_actuel) $ca_precedent_pmtd_usd += $v['montant'];
}

$evolution_pct = 0; $symbole_evol = '➖'; $color_evol = 'text-muted';
if ($ca_precedent_pmtd_usd > 0) {
    $evolution_pct = round((($ca_actuel_usd - $ca_precedent_pmtd_usd) / $ca_precedent_pmtd_usd) * 100, 1);
    if ($evolution_pct > 0) { $symbole_evol = '▲'; $color_evol = 'text-success'; } elseif ($evolution_pct < 0) { $symbole_evol = '▼'; $color_evol = 'text-danger'; }
}

$projection_usd = max(1, (int)$jour_actuel) > 0 ? ($ca_actuel_usd / max(1, (int)$jour_actuel)) * date('t') : 0;

ksort($stats_hebdo);
$labels_hebdo = []; $data_hebdo = [];
foreach ($stats_hebdo as $sem => $data) { $labels_hebdo[] = "Sem. " . substr($sem, 5); $data_hebdo[] = $data['usd']; }

function getCumulArray($tr, $target, $limit) {
    $daily = array_fill(1, 31, 0);
    if (isset($tr[$target])) foreach ($tr[$target] as $t) $daily[(int)date('d', strtotime($t['date_vente']))] += $t['montant'];
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
    <title>🧠 Brainrot Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f5f7fa; color: #2e2e2e; padding-top: 40px; }
        
        h1.header-main { color: #4a6fa5; font-weight: 600; font-size: 2.1rem; border-bottom: 2px solid #cfd8e3; padding-bottom: 10px; }
        .card { border: none; border-radius: 1rem; background-color: #ffffff; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05); }
        .kpi-value { font-size: 2.1rem; font-weight: 700; color: #4a6fa5; }
        .kpi-value.eur { color: #a54a4a; }
        .evolution-kpi { font-size: 2.1rem; font-weight: 700; }
        
        .action-button { background: linear-gradient(135deg, #4a6fa5, #597db7); border: none; color: white; font-weight: 600; border-radius: 0.75rem; padding: 14px 20px; transition: all 0.3s ease; box-shadow: 0 3px 10px rgba(74,111,165,0.25); }
        .action-button:hover { background: linear-gradient(135deg, #3b5d8a, #4a6fa5); transform: translateY(-2px); }
        
        .login-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(245, 247, 250, 0.9); backdrop-filter: blur(15px); z-index: 9999; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 90%; max-width: 400px; padding: 2rem; background: white; border-radius: 1.5rem; box-shadow: 0 20px 50px rgba(0,0,0,0.1); text-align: center; }
        .login-input { border-radius: 0.75rem; padding: 12px; border: 2px solid #e7effa; width: 100%; margin-bottom: 1rem; text-align: center; }
        
        /* --- STYLE DES BADGES STATS (DESIGN PC) --- */
        .badge-stat { 
            background: white; padding: 15px 20px; border-radius: 12px; 
            display: flex; align-items: center; gap: 15px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.03); border: 1px solid #eee; 
            transition: transform 0.2s; height: 100%;
        }
        .badge-stat i { font-size: 1.4rem; }
        .badge-stat div { display: flex; flex-direction: column; line-height: 1.2; }
        .badge-stat span.val { font-weight: 700; font-size: 1.1rem; color: #2e2e2e; }
        .badge-stat span.lbl { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }

        /* --- TABS --- */
        .monthly-tabs-nav { display: flex; flex-wrap: wrap; justify-content: center; margin-bottom: 20px; padding: 10px; background-color: #e7effa; border-radius: 0.75rem; }
        .monthly-tab-btn { background: none; border: none; padding: 8px 15px; margin: 5px; border-radius: 20px; font-weight: 500; color: #4a6fa5; }
        .monthly-tab-btn.active { background-color: #4a6fa5; color: white; font-weight: 700; }
        .monthly-content { display: none; } .monthly-content.active { display: block; }
        
        .brrbrrpatapim-image { position: absolute; bottom: 135%; right: 150px; width: 140px; z-index: 3; pointer-events: none; }
        .chart-container { position: relative; height: 380px; width: 100%; }

        /* ======================================================
           📱 MOBILE OPTIMIZATION (Layout "1 par 1")
           ====================================================== */
        @media (max-width: 768px) {
            body { padding-top: 20px; }
            
            /* Header plus propre */
            h1.header-main { 
                font-size: 1.4rem; 
                text-align: center; 
                border-bottom: none; 
                margin-bottom: 5px !important;
            }
            
            /* On cache l'image qui gêne sur mobile */
            .brrbrrpatapim-image { display: none !important; }
            
            /* Bouton "Ajouter" plus gros et visible */
            .action-button { 
                width: 100%; 
                padding: 16px; 
                font-size: 1.1rem;
                margin-bottom: 10px;
            }

            /* ⚠️ TRANSFORMER LES PETITS BADGES EN LIGNES (1 par 1) */
            .badge-stat {
                justify-content: space-between; /* Espace entre icone et texte */
                padding: 15px;
                border-left: 5px solid #4a6fa5; /* Petite déco */
            }
            .badge-stat i { font-size: 1.6rem; }
            .badge-stat div { align-items: flex-end; } /* Texte aligné à droite */
            .badge-stat span.val { font-size: 1.3rem; }
            
            /* Espacement vertical entre les éléments empilés */
            .g-3 > div { margin-bottom: 5px; }
            
            /* Tabs défilables horizontalement */
            .monthly-tabs-nav {
                flex-wrap: nowrap;
                overflow-x: auto;
                justify-content: flex-start;
                padding: 10px;
            }
            .monthly-tab-btn { flex: 0 0 auto; }
        }
    </style>
</head>
<body>

<?php if (!$is_logged_in): ?>
<div class="login-overlay">
    <div class="login-card">
        <h2 style="color: #4a6fa5; font-weight: 800;">Brainrot</h2>
        <p class="text-muted small mb-4">Accès sécurisé</p>
        <form method="POST">
            <input type="text" name="username" class="login-input" placeholder="Utilisateur" required>
            <input type="password" name="password" class="login-input" placeholder="Mot de passe" required>
            <?php if ($login_error): ?><div class="text-danger small mb-3"><?= $login_error ?></div><?php endif; ?>
            <button type="submit" name="login_btn" class="btn action-button w-100">Connexion</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-3">
        <h1 class="header-main m-0">Tableau de Bord</h1>
        <?php if ($is_logged_in): ?>
            <a href="?logout=1" class="btn btn-sm btn-outline-danger mt-2 mt-md-0"><i class="fas fa-power-off"></i> Déconnexion</a>
        <?php endif; ?>
    </div>
    
    <div class="text-center mb-4 text-muted">
        <small>Bonjour <strong><?= isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Invité' ?></strong> <span class="badge bg-light text-dark border"><?= ucfirst($user_role) ?></span></small>
    </div>

    <?php if($user_role === 'admin'): ?>
    <div class="d-flex justify-content-center mb-4">
        <a href="add_retrait.php" class="btn action-button shadow-sm">
            <i class="fas fa-plus-circle me-2"></i> Ajouter un retrait (USD)
        </a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-5">
        <div class="col-md-4 col-12">
            <div class="badge-stat">
                <i class="fas fa-trophy text-warning"></i>
                <div><span class="val"><?= number_format($max_win, 0, ',', ' ') ?> $</span><span class="lbl">Record Retrait</span></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="badge-stat">
                <i class="fas fa-chart-line text-info"></i>
                <div><span class="val"><?= number_format($avg_win, 0, ',', ' ') ?> $</span><span class="lbl">Moyenne / Retrait</span></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="badge-stat">
                <i class="fas fa-bullseye text-danger"></i>
                <div><span class="val"><?= number_format($projection_usd, 0, ',', ' ') ?> $</span><span class="lbl">Projection Fin Mois</span></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-3 col-6">
            <div class="card p-3 text-center h-100"> 
                <img src="brrbrrpatapim.png" alt="Brainrot" class="brrbrrpatapim-image">
                <div class="text-muted small">💸 CA USD Mois</div>
                <div class="kpi-value"><?= number_format($stat_actuelle['ca_mensuel_usd'], 2, ',', ' ') ?> $</div>
                <div class="text-muted small">≈ <?= number_format($stat_actuelle['ca_mensuel_eur'], 2, ',', ' ') ?> €</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card p-3 text-center h-100">
                <div class="text-muted small">💰 CA EUR Réel</div>
                <div class="kpi-value eur" style="font-size: 1.6rem; margin-top:5px;"><?= number_format($stat_actuelle['ca_mensuel_eur'], 2, ',', ' ') ?> €</div>
                <div class="text-muted small"><?= traduire_mois(date('F Y')) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card p-3 text-center h-100">
                <div class="text-muted small">📊 Évolution (J1-<?= (int)$jour_actuel ?>)</div>
                <div class="evolution-kpi <?= $color_evol ?>"><?= $symbole_evol ?> <?= number_format(abs($evolution_pct), 1, ',', ' ') ?> %</div>
                <div class="text-muted small" style="line-height: 1.2;"><?php echo 'vs ' . traduire_mois(date('F', strtotime('last month'))); ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card p-3 text-center h-100">
                <div class="text-muted small">📈 CA Total (12 mois)</div>
                <div class="kpi-value"><?= number_format($total_ca_usd, 2, ',', ' ') ?> $</div>
                <div class="text-muted small">Soit <?= number_format($total_ca_eur, 2, ',', ' ') ?> €</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card p-4">
                <h5 class="mb-3" style="color: #4a6fa5; font-weight: 600;">🏃 La Course : Ce mois vs Mois Dernier (Cumulé USD)</h5>
                <div class="chart-container"><canvas id="cumulativeChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card p-4">
                <h5 class="text-center mb-3">📈 CA Hebdomadaire</h5>
                <div class="chart-container"><canvas id="weeklyChart"></canvas></div>
            </div>
        </div>
    </div>
    
    <div class="history-card mb-5">
        <h2 class="history-title">🗓️ Historique</h2>
        <div class="monthly-tabs-nav" id="monthly-tabs">
            <?php $is_first = true; foreach ($retraits_par_mois as $mois_key => $retraits): $active_class = $is_first ? 'active' : ''; ?>
                <button class="monthly-tab-btn <?= $active_class ?>" data-target="<?= $mois_key ?>"><?= traduire_mois(date('F Y', strtotime($mois_key . '-01'))) ?></button>
            <?php $is_first = false; endforeach; ?>
        </div>
        <div id="monthly-content-container">
            <?php $is_first = true; foreach ($retraits_par_mois as $mois_key => $retraits): $active_class = $is_first ? 'active' : ''; ?>
            <div id="content-<?= $mois_key ?>" class="monthly-content <?= $active_class ?>">
                <div class="table-responsive">
                    <table class="table table-hover table-striped detail-table table-custom">
                        <thead><tr><th>Date</th><th>USD</th><th>Taux</th><th>EUR</th></tr></thead>
                        <tbody>
                            <?php foreach ($retraits as $retrait): ?>
                            <tr>
                                <td><?= date('d/m', strtotime($retrait['date_vente'])) ?></td>
                                <td class="fw-bold" style="color:#4a6fa5;"><?= number_format($retrait['montant'], 2) ?> $</td>
                                <td><span class="rate-badge"><?= $retrait['taux_appliq'] ?></span></td>
                                <td class="text-muted"><?= number_format($retrait['montant_eur'], 2) ?> €</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php $is_first = false; endforeach; ?>
        </div>
    </div>

    <div class="history-card mb-5">
        <h2 class="history-title">📑 Récapitulatif</h2>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-custom">
                <thead><tr><th>Mois</th><th>#</th><th>USD</th><th>EUR</th></tr></thead>
                <tbody>
                    <?php foreach ($stats_mensuelles as $stat): ?>
                    <tr>
                        <td><?= ucfirst(traduire_mois(date('M y', strtotime($stat['mois'].'-01')))) ?></td>
                        <td><?= $stat['nb_retraits'] ?></td>
                        <td class="fw-bold" style="color:#4a6fa5;"><?= number_format($stat['ca_mensuel_usd'], 0) ?> $</td>
                        <td class="text-muted"><?= number_format($stat['ca_mensuel_eur'], 0) ?> €</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-info fw-bold">
                        <td>TOTAL</td>
                        <td><?= number_format($total_retraits, 0, ',', ' ') ?></td>
                        <td><?= number_format($total_ca_usd, 0, ',', ' ') ?> $</td>
                        <td><?= number_format($total_ca_eur, 0, ',', ' ') ?> €</td>
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
                { label: 'Ce Mois', data: <?= json_encode($data_cumul_actuel) ?>, borderColor: '#4a6fa5', backgroundColor: 'rgba(74, 111, 165, 0.1)', borderWidth: 3, pointRadius: 0, fill: true, tension: 0.3 },
                { label: 'Mois Dernier', data: <?= json_encode($data_cumul_prev) ?>, borderColor: '#95a5a6', backgroundColor: 'transparent', borderDash: [5, 5], borderWidth: 2, pointRadius: 0, fill: false, tension: 0.3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { borderDash: [2, 2] } } } }
    });

    // Chart Hebdo
    const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
    new Chart(weeklyCtx, {
        type: 'bar',
        data: { labels: <?= json_encode($labels_hebdo) ?>, datasets: [{ label: 'USD', data: <?= json_encode($data_hebdo) ?>, backgroundColor: 'rgba(74, 111, 165, 0.7)', borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true }, x: { } } }
    });

    // Tabs
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