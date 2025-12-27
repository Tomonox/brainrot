<?php
session_start(); // 1. On démarre la session (OBLIGATOIRE)

// =========================================================
// 🛡️ SÉCURITÉ : ACCÈS RESTREINT AUX ADMINS
// =========================================================
// Si l'utilisateur n'est pas connecté OU qu'il n'a pas le rôle 'admin'
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true || 
    !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    
    // On le vire vers l'accueil
    header("Location: index.php");
    exit;
}

// =========================================================
// 🌐 CONFIGURATION API SUPABASE
// =========================================================
$supabase_url = "https://glqreyurigjcqkmvftme.supabase.co/rest/v1/ventes_brainrot";
$api_key = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdscXJleXVyaWdqY3FrbXZmdG1lIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjYwMTIyNDMsImV4cCI6MjA4MTU4ODI0M30.Ia5zV8zwT5SqWrFO1161nwFaXKVI0uOasseKTQVMZgo";

// --- Taux de Conversion Fixe (Pour affichage seulement) ---
const TAUX_USD_EUR = 0.93; 

$message = '';

// =========================================================
// 📩 TRAITEMENT DU FORMULAIRE (Insertion via API)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $montant_usd = filter_input(INPUT_POST, 'montant', FILTER_VALIDATE_FLOAT);
    $date_vente = filter_input(INPUT_POST, 'date_vente', FILTER_SANITIZE_SPECIAL_CHARS); 
    
    $montant_eur = round($montant_usd * TAUX_USD_EUR, 2); 

    if ($montant_usd === false || $montant_usd <= 0) {
        $message = "❌ Erreur : Le montant doit être un nombre positif.";
    } elseif (empty($date_vente)) {
        $message = "❌ Erreur : La date est obligatoire.";
    } else {
        // Préparation des données JSON pour Supabase
        $data = [
            "montant"    => $montant_usd,
            "date_vente" => $date_vente,
            "devise"     => "USD"
        ];
        $payload = json_encode($data);

        // Configuration de la requête HTTP POST
        $opts = [
            "http" => [
                "method"  => "POST",
                "header"  => "apikey: " . $api_key . "\r\n" .
                             "Authorization: Bearer " . $api_key . "\r\n" .
                             "Content-Type: application/json\r\n" .
                             "Prefer: return=minimal\r\n",
                "content" => $payload
            ]
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($supabase_url, false, $context);

        if ($response !== FALSE) {
            $message = "✅ Retrait de <strong>" . number_format($montant_usd, 2, ',', ' ') . " $</strong> (≈ <strong>" . number_format($montant_eur, 2, ',', ' ') . " €</strong> au taux " . TAUX_USD_EUR . ") enregistré !";
        } else {
            $message = "❌ Erreur BDD : L'insertion via API a échoué. Vérifiez votre connexion.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>➕ Ajouter un Retrait (USD)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* ----------- TON DESIGN ORIGINAL CONSERVÉ ----------- */
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #2e2e2e;
            padding-top: 50px;
        }

        .card {
            border: none;
            border-radius: 1rem;
            background-color: #ffffff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            max-width: 520px;
            margin: 0 auto;
        }

        .header-main {
            color: #4a6fa5;
            font-weight: 600;
            font-size: 1.8rem;
        }

        .form-label-custom {
            color: #4a6fa5;
            font-weight: 600;
        }

        .alert-info {
            background-color: #e7effa;
            color: #2e2e2e;
            border: none;
            font-size: 0.9rem;
        }

        .action-button {
            background: linear-gradient(135deg, #4a6fa5, #597db7);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 0.75rem;
            padding: 12px 18px;
            transition: all 0.3s ease;
            display: block;
            margin: 0 auto;
            box-shadow: 0 3px 10px rgba(74, 111, 165, 0.25);
        }

        .action-button:hover {
            background: linear-gradient(135deg, #3b5d8a, #4a6fa5);
            box-shadow: 0 5px 14px rgba(74, 111, 165, 0.35);
            transform: translateY(-2px);
        }

        .action-button i {
            margin-right: 6px;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-bottom: 10px;
            color: #6c757d;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .back-link:hover {
            color: #4a6fa5;
            text-decoration: underline;
        }

        .alert-success {
            background-color: #eaf5ea;
            border: 1px solid #b5dfb5;
            color: #2e7d32;
        }

        .alert-danger {
            background-color: #fdeaea;
            border: 1px solid #f5b5b5;
            color: #c62828;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card p-4 shadow-sm">
        <h1 class="text-center mb-3 header-main"><i class="fas fa-plus-circle me-2"></i> Enregistrer un Retrait (USD)</h1>
        
        <p class="text-center mb-3">
            <span class="badge bg-success">Mode Administrateur Actif</span>
        </p>

        <a href="index.php" class="back-link">← Retour au tableau de bord</a>

        <div class="alert alert-info text-center">
            💱 Taux de conversion actuel : 1 USD = <strong><?= TAUX_USD_EUR ?> EUR</strong>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= strpos($message, '✅') !== false ? 'alert-success' : 'alert-danger' ?> text-center" role="alert">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="mb-3">
                <label for="date_vente" class="form-label form-label-custom">
                    <i class="far fa-calendar-alt me-1"></i> Date du Retrait :
                </label>
                <input type="date" id="date_vente" name="date_vente" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="mb-4">
                <label for="montant" class="form-label form-label-custom">
                    <i class="fas fa-money-bill-wave me-1"></i> Montant Reçu (USD $) :
                </label>
                <input type="number" id="montant" name="montant" step="0.01" min="0.01" class="form-control" placeholder="Ex: 50.00 USD" required>
            </div>

            <button type="submit" class="btn btn-lg action-button">
                <i class="fas fa-save"></i> Enregistrer ce retrait
            </button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>