$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Url($url, $theme, $size, $filename) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        '--virtual-time-budget=4000',
        "--screenshot=$out",
        $url
    )
    if ($theme -eq 'dark') {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode))"
}

# 1. Team Chat Desktop Light
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=light&chat=1" "light" "1440,920" "chat_upgrades_desktop_light.png"

# 2. Team Chat Desktop Dark
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=dark&chat=1" "dark" "1440,920" "chat_upgrades_desktop_dark.png"

# 3. Message 3-Dot Menu
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=light&chat=1&menu=1" "light" "1440,920" "chat_3dot_menu_positioned.png"

# 4. Message Info Panel
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=light&info=1" "light" "1440,920" "chat_message_info_modal.png"

# 5. WebRTC Video Call & Screen Sharing Presentation
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=dark&call=1&present=1" "dark" "1440,920" "chat_webrtc_call_overlay.png"

# 6. Member Analytics & Workload Chart
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=light&member_chart=1" "light" "1440,920" "member_analytics_modal.png"

# 7. Mobile Chat Light
Capture-Url "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=light&chat=1" "light" "390,844" "chat_upgrades_mobile_light.png"

Get-ChildItem $artDir -Filter 'chat_*.png' | Select-Object Name, Length, LastWriteTime
