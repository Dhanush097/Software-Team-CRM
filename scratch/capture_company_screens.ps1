$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($theme, $size, $filename, $extraParams) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $url = "http://127.0.0.1:8000/scratch/auth_session.php?view=group_chart&theme=$theme&$extraParams"
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

# 1. Create Group Modal - Desktop Light & Dark
Capture-Screen 'light' '1440,960' 'create_group_modal_light.png' 'create_group=1'
Capture-Screen 'dark' '1440,960' 'create_group_modal_dark.png' 'create_group=1'

# 2. Edit Group Modal - Desktop Light
Capture-Screen 'light' '1440,960' 'edit_group_modal_light.png' 'edit_group=1'

# 3. Group List & Details View - Desktop Light
Capture-Screen 'light' '1440,960' 'groups_view_with_company_badges.png' ''

Get-ChildItem $artDir -Filter '*group*.png' | Sort-Object LastWriteTime -Descending | Select-Object -First 6 Name, Length, LastWriteTime
