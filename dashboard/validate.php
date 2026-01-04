<?php


/**
 * Authly SSL Public Key Extractor (PHP Version)
 * Mit Copy-to-Clipboard Button
 */

session_start();


if($_SESSION['username'] == "") {
    header("Location: /login/");
    exit();
}

$targetDomain = "authly.xyz";
$targetUrl = "https://authly.xyz";

$publicKeyString = null;

try {

    $streamContext = stream_context_create([
        "ssl" => [
            "capture_peer_cert" => true,
            "verify_peer"       => false,
            "verify_peer_name"  => false,
        ]
    ]);

    $client = @stream_socket_client(
        "ssl://$targetDomain:443",
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $streamContext
    );

    if ($client) {
        $params = stream_context_get_params($client);
        $cert   = $params["options"]["ssl"]["peer_certificate"] ?? null;

        if ($cert) {
            $pubKey = openssl_pkey_get_public($cert);

            if ($pubKey) {
                $keyDetails = openssl_pkey_get_details($pubKey);
                $publicKeyString = bin2hex($keyDetails["rsa"]["n"]);
            }
        }
    }

} catch (Exception $ex) {
    // Fehler werden unten ausgegeben
}

?>

<h2>PHP Public Key Extractor für <?= $targetUrl ?></h2>
<hr>

<?php if ($publicKeyString): ?>

    <p><strong>Extracted Public Key:</strong></p>

    <!-- Textbox mit Key -->
    <textarea id="publicKey" style="width:100%;height:120px;background:#111;color:#0f0;border:1px solid #333;padding:10px;border-radius:6px;">
<?=$publicKeyString?>
    </textarea>

    <br><br>

    <!-- Copy Button -->
    <button onclick="copyPubKey()" 
            style="background:#4f46e5;color:white;padding:10px 18px;border:none;border-radius:8px;cursor:pointer;">
        🔑 Copy Public Key
    </button>
    <button onclick="backtodashboard()" 
            style="background:#4f46e5;color:white;padding:10px 18px;border:none;border-radius:8px;cursor:pointer;">
        Back to Dashboard
    </button>

    <p id="copyStatus" style="color:#4ade80;margin-top:10px;"></p>

    <script>
        function copyPubKey() {
            let textArea = document.getElementById("publicKey");

            textArea.select();
            textArea.setSelectionRange(0, 999999); 

            navigator.clipboard.writeText(textArea.value.trim())
                .then(() => {
                    document.getElementById("copyStatus").innerText = "✔ Schlüssel erfolgreich kopiert!";
                })
                .catch(err => {
                    document.getElementById("copyStatus").innerText = "❌ Fehler beim Kopieren.";
                });
        }
        function backtodashboard() {
            window.location.href = "/dashboard/";
        }
    </script>

<?php else: ?>

    <p style="color:#f87171;"><strong>Fehler:</strong> Der Public Key konnte nicht extrahiert werden.</p>

<?php endif; ?>
