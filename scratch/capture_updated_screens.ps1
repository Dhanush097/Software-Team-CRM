$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($url, $size, $filename, $delayMs = 4000) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        "--virtual-time-budget=$delayMs",
        "--screenshot=$out",
        $url
    )
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (Size: $size, ExitCode: $($p.ExitCode))"
}

# 1. Welcome Intro Screen 1 (Logo Animation) - Desktop
Capture-Screen "http://127.0.0.1/software_team/index.php?screen=1" "1440,920" "welcome_intro_logo_desktop.png" 2000

# 2. Welcome Intro Screen 2 (Welcome Screen with Enter CRM) - Desktop
Capture-Screen "http://127.0.0.1/software_team/index.php?screen=2" "1440,920" "welcome_intro_screen_desktop.png" 3000

# 3. Welcome Intro Screen 2 - Mobile (iPhone 14)
Capture-Screen "http://127.0.0.1/software_team/index.php?screen=2" "390,844" "welcome_intro_screen_mobile.png" 3000

# 4. Clean Login Screen (No "Watch Welcome Intro" button) - Desktop
Capture-Screen "http://127.0.0.1/software_team/index.php?view=login#login" "1440,920" "login_screen_cleaned_desktop.png" 2000

# 5. Clean Login Screen - Mobile
Capture-Screen "http://127.0.0.1/software_team/index.php?view=login#login" "390,844" "login_screen_cleaned_mobile.png" 2000

# 6. Overwrite the old artifact login_screen_with_replay_button.png so the artifact folder has clean screens
Capture-Screen "http://127.0.0.1/software_team/index.php?view=login#login" "1440,920" "login_screen_with_replay_button.png" 2000

Get-ChildItem $artDir -Filter "*welcome*.png" | Select-Object Name, Length, LastWriteTime
Get-ChildItem $artDir -Filter "*login*.png" | Select-Object Name, Length, LastWriteTime
