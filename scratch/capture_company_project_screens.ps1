$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$artDir = 'C:\Users\vanga\.gemini\antigravity\brain\e1ec6121-03cd-4c78-81a5-92700267bcf1'

function Capture-Screen($theme, $size, $filename, $extraParams) {
    $out = Join-Path $artDir $filename
    if (Test-Path $out) { Remove-Item $out -Force }
    $url = "http://127.0.0.1:8000/scratch/auth_session.php?theme=$theme&$extraParams"
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        "--window-size=$size",
        '--virtual-time-budget=6000',
        "--screenshot=$out",
        $url
    )
    if ($theme -eq 'dark') {
        $args = @('--force-dark-mode') + $args
    }
    $p = Start-Process -FilePath $chrome -ArgumentList $args -Wait -PassThru
    Write-Host "Captured $filename (ExitCode: $($p.ExitCode))"
}

# 1. Project Detail showing Associated Company Card
Capture-Screen 'light' '1440,960' 'project_detail_with_associated_company.png' 'project_detail=1'

# 2. Add Project Modal with Auto-matched Company
Capture-Screen 'light' '1440,960' 'project_form_with_auto_linked_company.png' 'project_modal=1'

# 3. Company Detail showing Associated Projects (Tab 3)
Capture-Screen 'light' '1440,960' 'company_detail_with_associated_projects.png' 'company_detail=1'

# 4. Project Detail in Dark Mode
Capture-Screen 'dark' '1440,960' 'project_detail_dark_mode.png' 'project_detail=1'

Get-ChildItem $artDir -Filter '*project*company*.png' | Sort-Object LastWriteTime -Descending | Select-Object Name, Length, LastWriteTime
Get-ChildItem $artDir -Filter '*company*project*.png' | Sort-Object LastWriteTime -Descending | Select-Object Name, Length, LastWriteTime
