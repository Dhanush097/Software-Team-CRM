$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($theme, $size, $filename) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $url = "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=$theme"
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        '--virtual-time-budget=3000',
        "--screenshot=$out",
        $url
    )
    if ($theme -eq 'dark') {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode))"
}

Capture-Screen 'dark' '1440,920' 'group_chart_desktop_dark.png'
Capture-Screen 'light' '1440,920' 'group_chart_desktop_light.png'
Capture-Screen 'dark' '390,844' 'group_chart_mobile_dark.png'
Capture-Screen 'light' '390,844' 'group_chart_mobile_light.png'
Capture-Screen 'light' '768,1024' 'group_chart_tablet_light.png'

Get-ChildItem $artDir -Filter 'group_chart_*.png' | Select-Object Name, Length, LastWriteTime
