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
        '--virtual-time-budget=4000',
        "--screenshot=$out",
        $url
    )
    if ($isDark) {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode))"
}

# 1. Welcome Screen Light Mode (Desktop)
Capture-Screen "http://127.0.0.1:8000/index.php?intro=1&theme=light" "1440,920" "welcome_intro_screen_light.png" $false

# 2. Welcome Screen Dark Mode (Desktop)
Capture-Screen "http://127.0.0.1:8000/index.php?intro=1&theme=dark" "1440,920" "welcome_intro_screen_dark.png" $true

# 3. Welcome Screen Mobile View
Capture-Screen "http://127.0.0.1:8000/index.php?intro=1&theme=light" "390,844" "welcome_intro_mobile.png" $false

# 4. Login Screen with Watch Welcome Intro button
Capture-Screen "http://127.0.0.1:8000/index.php?skip_intro=1&theme=light" "1440,920" "login_screen_with_replay_button.png" $false

# 5. Settings Screen with Replay Intro option
Capture-Screen "http://127.0.0.1:8000/scratch/auth_session.php?view=settings&theme=light" "1440,920" "settings_view_with_replay_intro.png" $false

Get-ChildItem $artDir -Filter "*welcome*.png" | Select-Object Name, Length, LastWriteTime
Get-ChildItem $artDir -Filter "*login*.png" | Select-Object Name, Length, LastWriteTime
Get-ChildItem $artDir -Filter "*settings*.png" | Select-Object Name, Length, LastWriteTime
