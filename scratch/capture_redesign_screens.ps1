$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($url, $size, $filename, $isDark = $false) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        '--virtual-time-budget=3000',
        "--screenshot=$out",
        $url
    )
    if ($isDark) {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode), Size: $((Get-Item $out).Length) bytes)"
}

# 1. Dashboard Light Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=dashboard&theme=light" "1440,920" "crm_redesign_dashboard_light.png" $false

# 2. Dashboard Dark Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=dashboard&theme=dark" "1440,920" "crm_redesign_dashboard_dark.png" $true

# 3. Projects View Light Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=projects&theme=light" "1440,920" "crm_redesign_projects_light.png" $false

# 4. Invoices View Light Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=invoices&theme=light" "1440,920" "crm_redesign_invoices_light.png" $false

# 5. Group Chat Light Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=chat&theme=light&chat=1" "1440,920" "crm_redesign_chat_light.png" $false

# 6. Mobile Dashboard Light Mode
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=dashboard&theme=light" "390,844" "crm_redesign_mobile_light.png" $false

Get-ChildItem $artDir -Filter "crm_redesign_*.png" | Select-Object Name, Length, LastWriteTime
